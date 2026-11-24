<?php

declare(strict_types=1);

namespace App\Services\Import\Amtelco;

use App\Models\ClientAccountAlias;
use App\Models\ClientLegacyRecord;
use App\Models\Team;
use App\Models\User;
use App\Services\Clients\ClientProvisioner;
use App\Services\Import\XlsxReader;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creates Orbital clients from an Amtelco Infinity export workbook
 * ("NCI Client Data by Account": one tab per report, every tab keyed
 * and sorted by Account #).
 *
 * Per included account:
 *   - a client (Team) with the account number, a title-cased name, the
 *     answer phrase as its greeting, the client status as its pinned
 *     operator note, and its passcode (encrypted);
 *   - account aliases for DID-tab values that differ from the account
 *     number (on Amtelco these are other numbers the account answers
 *     to, not phone numbers);
 *   - every tab's rows for that account, verbatim, as legacy records.
 *
 * Re-runnable. Clients are matched by account number. A client this
 * importer created before gets its archive and aliases refreshed; its
 * name, greeting, note and passcode are only overwritten with
 * `refresh`, because operators may have corrected them since. A client
 * with the same account number that this importer did NOT create is
 * never touched: it is reported as a conflict.
 *
 * In a dry run the workbook is read in full, so the report is exactly
 * what a real run would do, but nothing is written.
 */
class AmtelcoImporter
{
    public const IMPORT_USER_EMAIL = 'amtelco-import@orbital.invalid';

    public const IMPORT_USER_NAME = 'Amtelco Import';

    public const ALIAS_SOURCE = 'amtelco:did';

    private const SHEET_ACCOUNTS = 'Accounts';

    private const SHEET_DIDS = 'DIDs';

    /** Tabs archived per account: sheet name => legacy record section. */
    private const ARCHIVED_SHEETS = [
        'Setup' => 'setup',
        'Details' => 'details',
        'Info Pages' => 'info_pages',
        'Forms' => 'forms',
        'Dial Lists' => 'dial_lists',
        'Transfer Lists' => 'transfer_lists',
        'Dist Lists' => 'dist_lists',
        'Other Config' => 'other_config',
    ];

    /** Tabs whose rows are lines of text, also stored joined as `text`. */
    private const TEXT_SECTIONS = ['info_pages', 'forms'];

    private const KEY = 'Account #';

    private const NAME = 'Client Name';

    public function __construct(
        private readonly ClientProvisioner $provisioner,
        private readonly AccountFilter $filter,
        private readonly ClientNameFormatter $names,
    ) {}

    /**
     * @param  array<int, int>  $forceInclude  account numbers to import whatever the filter says
     * @param  array<int, int>  $forceExclude  account numbers to skip whatever the filter says
     * @param  Closure(string): void|null  $progress
     */
    public function import(
        string $path,
        bool $dryRun = false,
        bool $refresh = false,
        array $forceInclude = [],
        array $forceExclude = [],
        string $timezone = 'America/New_York',
        string $tier = 'free',
        ?Closure $progress = null,
    ): ImportReport {
        $progress ??= fn (string $message) => null;
        $report = new ImportReport($dryRun);
        $book = new XlsxReader($path);

        foreach ([self::SHEET_ACCOUNTS, self::SHEET_DIDS, ...array_keys(self::ARCHIVED_SHEETS)] as $sheet) {
            if (! $book->hasSheet($sheet)) {
                throw new RuntimeException("Workbook has no '{$sheet}' tab; is this the Amtelco client export?");
            }
        }

        $progress('Reading accounts');
        $accounts = $this->readAccounts($book);
        $aliases = $this->readAliases($book, $accounts);

        // Who already holds which account number, and which of those
        // clients this importer created.
        $existing = Team::query()
            ->where('personal_team', false)
            ->whereNotNull('account_number')
            ->pluck('id', 'account_number')
            ->all();
        $ours = ClientLegacyRecord::query()
            ->where('source', ClientLegacyRecord::SOURCE_AMTELCO)
            ->where('section', 'account')
            ->pluck('team_id')
            ->flip()
            ->all();

        $owner = $dryRun ? null : $this->importUser();

        /** @var array<int, int> $teamIds account number => team id, for archiving */
        $teamIds = [];

        $progress('Creating and updating clients');
        foreach ($accounts as $number => $row) {
            $sourceName = (string) $row[self::NAME];
            $name = $this->names->format($sourceName);
            $decision = $this->filter->decide($row);
            $accountAliases = $aliases[$number] ?? [];

            if (in_array($number, $forceExclude, true)) {
                $decision = ['include' => false, 'rule' => AccountFilter::RULE_FORCED_EXCLUDE, 'reason' => '--exclude', 'flags' => $decision['flags']];
            } elseif (in_array($number, $forceInclude, true) && ! $decision['include']) {
                $decision['flags'][] = "included by option despite {$decision['rule']}: {$decision['reason']}";
                $decision = ['include' => true, 'rule' => null, 'reason' => null, 'flags' => $decision['flags']];
            }

            if (! $decision['include']) {
                $report->account($number, $sourceName, $name, ImportReport::SKIP, "{$decision['rule']}: {$decision['reason']}", $decision['flags'], $accountAliases);

                continue;
            }

            $teamId = $existing[$number] ?? null;

            if ($teamId !== null && ! isset($ours[$teamId])) {
                $report->account($number, $sourceName, $name, ImportReport::CONFLICT, "account number already used by client #{$teamId}, which this import did not create", $decision['flags'], $accountAliases);

                continue;
            }

            $action = $teamId === null ? ImportReport::CREATE : ImportReport::UPDATE;
            $report->account($number, $sourceName, $name, $action, $action === ImportReport::UPDATE && ! $refresh ? 'archive and aliases only (use --refresh to overwrite client fields)' : '', $decision['flags'], $accountAliases);

            if ($dryRun) {
                $teamIds[$number] = 0;
                $report->archived('account');

                continue;
            }

            $teamIds[$number] = DB::transaction(function () use ($teamId, $number, $row, $name, $owner, $refresh, $timezone, $tier, $accountAliases, $report) {
                $fields = [
                    'name' => $name,
                    'greeting' => $this->clean($row['Answer Phrase'] ?? ''),
                    'operator_note' => Str::limit((string) $this->clean($row['Client Status'] ?? ''), 255, '') ?: null,
                    'passcode' => $this->passcode($row['Passcode'] ?? ''),
                ];

                if ($teamId === null) {
                    $team = Team::forceCreate($fields + [
                        'user_id' => $owner->id,
                        'account_number' => $number,
                        'personal_team' => false,
                        'timezone' => $timezone,
                        'tier' => $tier,
                    ]);
                    $this->provisioner->provision($team, $owner);
                } else {
                    $team = Team::findOrFail($teamId);
                    if ($refresh) {
                        $team->forceFill($fields)->save();
                    }
                }

                $this->syncAliases($team, $accountAliases);
                $this->archive($team->id, $number, 'account', $row);
                $report->archived('account');

                return $team->id;
            });
        }

        $this->flagAliasClashes($report, $aliases, $accounts, $existing, $ours);

        foreach (self::ARCHIVED_SHEETS as $sheet => $section) {
            $progress("Archiving {$sheet}");
            $this->archiveSheet($book, $sheet, $section, $teamIds, $report, $dryRun);
        }

        return $report;
    }

    /**
     * @return array<int, array<string, string>> account number => Accounts row
     */
    private function readAccounts(XlsxReader $book): array
    {
        $accounts = [];
        foreach ($book->rows(self::SHEET_ACCOUNTS) as $row) {
            $number = $this->accountNumber($row, self::SHEET_ACCOUNTS);
            if (isset($accounts[$number])) {
                throw new RuntimeException("Account {$number} appears twice on the Accounts tab.");
            }
            $accounts[$number] = $row;
        }

        return $accounts;
    }

    /**
     * DID-tab values other than the account's own number.
     *
     * @param  array<int, array<string, string>>  $accounts
     * @return array<int, array<int, int>> account number => alias numbers
     */
    private function readAliases(XlsxReader $book, array $accounts): array
    {
        $aliases = [];
        foreach ($book->rows(self::SHEET_DIDS) as $row) {
            $number = $this->accountNumber($row, self::SHEET_DIDS);
            $did = trim((string) ($row['DID'] ?? ''));
            if (! ctype_digit($did) || (int) $did === $number || ! isset($accounts[$number])) {
                continue;
            }
            $aliases[$number][] = (int) $did;
        }

        return array_map(fn (array $list) => array_values(array_unique($list)), $aliases);
    }

    /**
     * Flag aliases that collide with another account's number, with an
     * existing client's number, or with another account's alias.
     *
     * @param  array<int, array<int, int>>  $aliases
     * @param  array<int, array<string, string>>  $accounts
     * @param  array<int, int>  $existing  account number => team id
     * @param  array<int, int>  $ours  team id => (flipped)
     */
    private function flagAliasClashes(ImportReport $report, array $aliases, array $accounts, array $existing, array $ours): void
    {
        $holders = [];
        foreach ($aliases as $number => $list) {
            foreach ($list as $alias) {
                $holders[$alias][] = $number;
            }
        }

        $actions = array_map(fn (array $row) => $row['action'], $report->accounts());

        foreach ($aliases as $number => $list) {
            foreach ($list as $alias) {
                if (isset($accounts[$alias])) {
                    $other = $this->names->format((string) $accounts[$alias][self::NAME]);
                    $state = ($actions[$alias] ?? null) === ImportReport::SKIP ? 'skipped' : 'imported';
                    $report->flag($number, "alias {$alias} is also the number of account {$alias} ({$other}, {$state})");
                } elseif (isset($existing[$alias]) && ! isset($ours[$existing[$alias]])) {
                    $report->flag($number, "alias {$alias} is the account number of existing client #{$existing[$alias]}");
                }

                $others = array_diff($holders[$alias], [$number]);
                if ($others !== []) {
                    $report->flag($number, "alias {$alias} is also an alias of account ".implode(', ', $others));
                }
            }
        }
    }

    /**
     * Stream one tab and store each account's rows as a legacy record.
     *
     * @param  array<int, int>  $teamIds  account number => team id (0 in a dry run)
     */
    private function archiveSheet(XlsxReader $book, string $sheet, string $section, array $teamIds, ImportReport $report, bool $dryRun): void
    {
        $current = null;
        $buffer = [];
        $seen = [];
        $pending = 0;

        // Writes are grouped 100 accounts to a transaction: one commit
        // per account is thousands of fsyncs for nothing.
        if (! $dryRun) {
            DB::beginTransaction();
        }

        $flush = function () use (&$current, &$buffer, &$pending, $teamIds, $section, $report, $dryRun): void {
            if ($current !== null && isset($teamIds[$current])) {
                if (! $dryRun) {
                    $payload = ['rows' => $buffer];
                    if (in_array($section, self::TEXT_SECTIONS, true)) {
                        $payload['text'] = implode("\n", array_map(fn (array $row) => rtrim((string) ($row['Text'] ?? '')), $buffer));
                    }
                    $this->archive($teamIds[$current], $current, $section, $payload);
                    if (++$pending >= 100) {
                        DB::commit();
                        DB::beginTransaction();
                        $pending = 0;
                    }
                }
                $report->archived($section);
            }
            $buffer = [];
        };

        try {

            foreach ($book->rows($sheet) as $row) {
                $number = $this->accountNumber($row, $sheet);

                if ($number !== $current) {
                    $flush();
                    if (isset($seen[$number])) {
                        throw new RuntimeException("The '{$sheet}' tab is not grouped by account: account {$number} appears in two places.");
                    }
                    $seen[$number] = true;
                    $current = $number;
                }

                unset($row[self::KEY], $row[self::NAME]);
                $buffer[] = $row;
            }

            $flush();
        } catch (\Throwable $e) {
            if (! $dryRun) {
                DB::rollBack();
            }

            throw $e;
        }

        if (! $dryRun) {
            DB::commit();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function archive(int $teamId, int $accountNumber, string $section, array $payload): void
    {
        ClientLegacyRecord::query()->updateOrCreate(
            ['team_id' => $teamId, 'source' => ClientLegacyRecord::SOURCE_AMTELCO, 'section' => $section],
            ['source_account_number' => $accountNumber, 'payload' => $payload],
        );
    }

    /**
     * @param  array<int, int>  $aliases
     */
    private function syncAliases(Team $team, array $aliases): void
    {
        ClientAccountAlias::query()
            ->where('team_id', $team->id)
            ->where('source', self::ALIAS_SOURCE)
            ->whereNotIn('account_number', $aliases ?: [-1])
            ->delete();

        foreach ($aliases as $alias) {
            ClientAccountAlias::query()->firstOrCreate(
                ['team_id' => $team->id, 'account_number' => $alias],
                ['source' => self::ALIAS_SOURCE],
            );
        }
    }

    /**
     * The service user that owns imported clients until their real
     * people are invited. It can't log in: its password is random and
     * thrown away, and the .invalid address can never receive mail.
     */
    private function importUser(): User
    {
        $user = User::query()->where('email', self::IMPORT_USER_EMAIL)->first();
        if ($user) {
            return $user;
        }

        return DB::transaction(function () {
            $user = User::forceCreate([
                'name' => self::IMPORT_USER_NAME,
                'email' => self::IMPORT_USER_EMAIL,
                'password' => Str::password(64),
                'email_verified_at' => now(),
            ]);

            // Every Jetstream user has a personal team.
            $team = Team::forceCreate([
                'user_id' => $user->id,
                'name' => 'Amtelco Import',
                'personal_team' => true,
            ]);
            $user->forceFill(['current_team_id' => $team->id])->save();
            $user->teams()->attach($team, ['role' => 'owner']);

            return $user;
        });
    }

    /**
     * @param  array<string, string>  $row
     */
    private function accountNumber(array $row, string $sheet): int
    {
        $value = trim((string) ($row[self::KEY] ?? ''));
        if (! ctype_digit($value)) {
            throw new RuntimeException("Row on the '{$sheet}' tab has a non-numeric account number: '{$value}'.");
        }

        return (int) $value;
    }

    private function clean(string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : $value;
    }

    /** Amtelco stores "no passcode" as 0. */
    private function passcode(string $value): ?string
    {
        $value = trim($value);

        return $value === '' || $value === '0' ? null : $value;
    }
}
