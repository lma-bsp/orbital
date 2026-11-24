<?php

declare(strict_types=1);

namespace App\Services\Import\Amtelco;

use App\Models\ClientAccountAlias;
use App\Models\ClientLegacyRecord;
use App\Models\DirectoryEntry;
use App\Models\DirectoryFieldDefinition;
use App\Models\DirectoryTag;
use App\Models\SharedDirectory;
use App\Models\Team;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * Loads NCI's Amtelco directories (nci_directories.json, schema
 * nci.directories/1) into Orbital's directory.
 *
 *   - A directory whose account number (or an alias) belongs to a
 *     client created by the Amtelco client import fills that client's
 *     directory. Every listing becomes an entry, tagged Contact,
 *     Routing, Instruction or Note, and with the directory's name when
 *     a client has more than one.
 *   - The provider master index ("0001 DIR") becomes one shared
 *     directory, "NCI Providers (Amtelco)".
 *   - Every other directory becomes its own shared directory, attached
 *     to no client, so a person can attach it where it belongs.
 *
 * Entries are matched on their Amtelco listing id (the `amtelco_ref`
 * field), so a re-run creates only what is missing. Entries that exist
 * are left as operators may have edited them, unless `refresh`.
 */
class DirectoryImporter
{
    public const SCHEMA = 'nci.directories/1';

    public const PROVIDERS_DIRECTORY = 'NCI Providers (Amtelco)';

    public const SHARED_PREFIX = 'Amtelco ';

    /** Listing record_type => tag name. */
    private const KIND_TAGS = [
        'contact' => 'Contact',
        'routing' => 'Routing',
        'instruction' => 'Instruction',
        'note' => 'Note',
    ];

    /** The provisioner's default fields, for shared directories, which start with none. */
    private const BASE_FIELDS = [
        'name' => ['Name', 'text', 10, 'name'],
        'organization' => ['Organization', 'text', 20, 'organization'],
        'department' => ['Department', 'text', 30, 'none'],
        'title' => ['Title', 'text', 40, 'none'],
        'primary_phone' => ['Primary phone', 'phone', 50, 'phone'],
        'email' => ['Email', 'email', 60, 'email'],
        'notes' => ['Notes', 'textarea', 70, 'none'],
    ];

    /** @var array<string, int> "team:slug" or "shared:slug" => tag id */
    private array $tagIds = [];

    public function __construct(
        private readonly DirectoryListingMapper $mapper,
        private readonly ClientNameFormatter $names,
    ) {}

    /**
     * @param  Closure(string): void|null  $progress
     */
    public function import(string $path, bool $dryRun = false, bool $refresh = false, ?Closure $progress = null): DirectoryImportReport
    {
        $progress ??= fn (string $message) => null;
        $data = $this->load($path);
        $report = new DirectoryImportReport($dryRun);

        // Clients the Amtelco import created, by account number and alias.
        $ours = ClientLegacyRecord::query()
            ->where('source', ClientLegacyRecord::SOURCE_AMTELCO)
            ->where('section', 'account')
            ->pluck('team_id')
            ->all();
        $byNumber = Team::query()->whereIn('id', $ours)->pluck('id', 'account_number')->all();
        $byAlias = ClientAccountAlias::query()->whereIn('team_id', $ours)->pluck('team_id', 'account_number')->all();
        $accountNames = Team::query()->whereIn('id', $ours)->pluck('name', 'account_number')->map(fn ($n) => (string) $n)->all();

        // Which directories go to which client.
        $perTeam = [];
        $unmatched = [];
        foreach ($data['directories'] as $directory) {
            $numbers = DirectoryListingMapper::expandNumbers($directory['linked_numbers'] ?? [], (string) ($directory['directory_id'] ?? ''));
            $teams = [];
            foreach ($numbers as $number) {
                if (isset($byNumber[$number])) {
                    $teams[$byNumber[$number]] = true;
                } elseif (isset($byAlias[$number])) {
                    $teams[$byAlias[$number]] = true;
                }
            }

            if ($teams === []) {
                $unmatched[] = [$directory, $numbers === [] ? 'no account number' : 'account '.implode(', ', $numbers).' is not an imported client'];

                continue;
            }
            foreach (array_keys($teams) as $teamId) {
                $perTeam[$teamId][] = $directory;
            }
        }

        $providers = $data['providers'] ?? [];
        unset($data);

        $progress('Client directories ('.count($perTeam).' clients)');
        foreach ($perTeam as $teamId => $directories) {
            $run = function () use ($teamId, $directories, $accountNames, $refresh, $report, $dryRun) {
                if (! $dryRun) {
                    $this->ensureFields(['team_id' => $teamId]);
                    $this->archiveDirectories($teamId, $directories);
                }
                $tagByDirectory = count($directories) > 1;
                foreach ($directories as $directory) {
                    $counts = $this->importListings(['team_id' => $teamId], $directory['listings'] ?? [], $tagByDirectory ? (string) $directory['directory_id'] : null, $accountNames, $refresh, $dryRun);
                    $report->directory((string) $directory['directory_id'], 'client #'.$teamId, $counts);
                }
            };
            $dryRun ? $run() : DB::transaction($run);
        }

        unset($perTeam);

        $progress('Provider index');
        if ($providers !== []) {
            $run = function () use ($providers, $accountNames, $refresh, $report, $dryRun) {
                $owner = $dryRun ? null : $this->sharedDirectory(self::PROVIDERS_DIRECTORY, 'Master provider index from Amtelco ("0001 DIR"): every provider with their specialty, town, main number and the accounts they belong to. Not attached to any client.');
                $listings = array_map(fn (array $p) => $this->providerAsListing($p), $providers);
                $counts = $this->importListings($owner ? ['shared_directory_id' => $owner->id] : [], $listings, null, $accountNames, $refresh, $dryRun);
                $report->directory('0001 DIR', 'shared: '.self::PROVIDERS_DIRECTORY, $counts);
            };
            $dryRun ? $run() : DB::transaction($run);
        }

        $progress('Unmatched directories ('.count($unmatched).')');
        foreach ($unmatched as [$directory, $reason]) {
            $id = (string) $directory['directory_id'];
            if (($directory['listings'] ?? []) === []) {
                $report->directory($id, 'skipped: no listings', ['created' => 0, 'updated' => 0, 'unchanged' => 0]);

                continue;
            }

            $run = function () use ($directory, $id, $reason, $accountNames, $refresh, $report, $dryRun) {
                $name = self::SHARED_PREFIX.$id;
                $owner = $dryRun ? null : $this->sharedDirectory($name, "Amtelco directory {$id} ({$directory['listings_count']} listings). Not attached to any client: {$reason}.");
                $counts = $this->importListings($owner ? ['shared_directory_id' => $owner->id] : [], $directory['listings'], null, $accountNames, $refresh, $dryRun);
                $report->directory($id, "shared: {$name} ({$reason})", $counts);
            };
            $dryRun ? $run() : DB::transaction($run);
        }

        return $report;
    }

    /**
     * @return array{directories: array<int, array<string, mixed>>, providers: array<int, array<string, mixed>>}
     */
    private function load(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Cannot read {$path}");
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("{$path} is not valid JSON: {$e->getMessage()}");
        }

        if (($data['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException("{$path} is not an Amtelco directory export (expected schema ".self::SCHEMA.').');
        }

        return ['directories' => $data['directories'] ?? [], 'providers' => $data['providers'] ?? []];
    }

    /**
     * @param  array{team_id?: int, shared_directory_id?: int}  $owner  empty in a dry run
     * @param  array<int, array<string, mixed>>  $listings
     * @param  array<int, string>  $accountNames
     * @return array{created: int, updated: int, unchanged: int}
     */
    private function importListings(array $owner, array $listings, ?string $directoryTag, array $accountNames, bool $refresh, bool $dryRun): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

        $existing = [];
        if (! $dryRun) {
            DirectoryEntry::query()
                ->withoutGlobalScope('team')
                ->where($owner)
                ->get()
                ->each(function (DirectoryEntry $entry) use (&$existing) {
                    $ref = $entry->values['amtelco_ref'] ?? null;
                    if (is_string($ref) && $ref !== '') {
                        $existing[$ref] = $entry;
                    }
                });
        }

        foreach ($listings as $listing) {
            $mapped = $this->mapper->map($listing, $accountNames);
            $ref = $mapped['values']['amtelco_ref'];
            $entry = $existing[$ref] ?? null;

            if ($entry !== null && ! $refresh) {
                $counts['unchanged']++;

                continue;
            }
            $counts[$entry ? 'updated' : 'created']++;

            if ($dryRun) {
                continue;
            }

            if ($entry) {
                // Fields this import owns are replaced as a set, so one it
                // no longer fills is emptied rather than left stale; fields
                // it never writes (an operator's own) are kept.
                $owned = array_flip([...array_keys(self::BASE_FIELDS), ...array_keys(DirectoryListingMapper::EXTRA_FIELDS)]);
                $entry->values = $mapped['values'] + array_diff_key($entry->values ?? [], $owned);
                $entry->save();
            } else {
                $entry = DirectoryEntry::query()->withoutGlobalScope('team')->create($owner + ['values' => $mapped['values']]);
                $existing[$ref] = $entry;
            }

            $tags = [self::KIND_TAGS[$mapped['kind']] ?? Str::title($mapped['kind'])];
            if ($directoryTag !== null) {
                $tags[] = 'Amtelco '.$directoryTag;
            }
            $entry->tags()->syncWithoutDetaching(array_map(fn (string $tag) => $this->tagId($owner, $tag), $tags));
        }

        return $counts;
    }

    /**
     * Directory tags belong to a client, or (team_id null) to the
     * platform, which is what shared-directory entries use.
     *
     * @param  array{team_id?: int, shared_directory_id?: int}  $owner
     */
    private function tagId(array $owner, string $name): int
    {
        $teamId = $owner['team_id'] ?? null;
        $slug = Str::slug($name);
        $key = ($teamId ?? 'platform').':'.$slug;

        return $this->tagIds[$key] ??= DirectoryTag::query()
            ->withoutGlobalScopes()
            ->firstOrCreate(['team_id' => $teamId, 'slug' => $slug], ['name' => $name])
            ->id;
    }

    /**
     * Add the fields an Amtelco listing needs. Existing definitions are
     * left alone: an operator may have renamed or reordered them.
     *
     * @param  array{team_id?: int, shared_directory_id?: int}  $owner
     */
    private function ensureFields(array $owner): void
    {
        $fields = [];
        foreach (self::BASE_FIELDS as $key => [$label, $type, $sort, $role]) {
            $fields[$key] = compact('label', 'type', 'sort', 'role');
        }
        foreach (DirectoryListingMapper::EXTRA_FIELDS as $key => [$label, $type, $sort]) {
            $fields[$key] = ['label' => $label, 'type' => $type, 'sort' => $sort, 'role' => 'none'];
        }

        foreach ($fields as $key => $field) {
            $definition = DirectoryFieldDefinition::query()
                ->withoutGlobalScopes()
                ->firstOrCreate($owner + ['key' => $key], [
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'role' => $field['role'],
                    'required' => false,
                    'sort_order' => $field['sort'],
                    'is_active' => true,
                    'help_text' => $key === 'amtelco_ref' ? 'The listing this entry came from in Amtelco. Used to match re-imports; leave as is.' : null,
                ]);

            // Earlier imports labelled this "Text / secure text"; the
            // macro behind it is a plain SMS. Only that exact label is
            // corrected, so a name an operator chose is kept.
            if ($key === 'text_to' && $definition->label === 'Text / secure text') {
                $definition->forceFill(['label' => $field['label']])->save();
            }

            // Routing lines and operator instructions have no phone, so
            // an imported directory can't insist on one.
            if ($key === 'primary_phone' && $definition->required) {
                $definition->forceFill(['required' => false])->save();
            }
        }
    }

    private function sharedDirectory(string $name, string $description): SharedDirectory
    {
        $directory = SharedDirectory::query()->firstOrCreate(['name' => $name], ['description' => $description]);
        $this->ensureFields(['shared_directory_id' => $directory->id]);

        return $directory;
    }

    /**
     * @param  array<int, array<string, mixed>>  $directories
     */
    private function archiveDirectories(int $teamId, array $directories): void
    {
        $existing = ClientLegacyRecord::query()
            ->withoutGlobalScope('team')
            ->where(['team_id' => $teamId, 'source' => ClientLegacyRecord::SOURCE_AMTELCO, 'section' => 'directories'])
            ->first();

        ClientLegacyRecord::query()->withoutGlobalScope('team')->updateOrCreate(
            ['team_id' => $teamId, 'source' => ClientLegacyRecord::SOURCE_AMTELCO, 'section' => 'directories'],
            [
                'source_account_number' => $existing?->source_account_number ?? Team::whereKey($teamId)->value('account_number'),
                'payload' => ['directories' => $directories],
            ],
        );
    }

    /**
     * The provider index has its own shape; make it look like a listing.
     *
     * @param  array<string, mixed>  $provider
     * @return array<string, mixed>
     */
    private function providerAsListing(array $provider): array
    {
        $attributes = array_filter([
            'specialty' => $provider['specialty'] ?? null,
            'account_numbers' => isset($provider['account_numbers']) ? [$provider['account_numbers']] : null,
        ]);

        $notes = [];
        if (! empty($provider['unresolved_account_numbers'])) {
            $notes[] = ['text' => 'Accounts with no Amtelco directory: '.DirectoryListingMapper::text($provider['unresolved_account_numbers'])];
        }
        if (! empty($provider['directory_ids'])) {
            $notes[] = ['text' => 'Amtelco directories: '.DirectoryListingMapper::text($provider['directory_ids'])];
        }

        return [
            'id' => '0001 DIR#'.($provider['n'] ?? ''),
            'record_type' => 'contact',
            'name' => $provider['name'] ?? [],
            'contact_methods' => $provider['contact_methods'] ?? [],
            'attributes' => $attributes,
            'notes' => $notes,
            'location' => trim(implode(', ', array_filter([
                isset($provider['town']) ? $this->names->format((string) $provider['town']) : null,
                $provider['state'] ?? null,
            ]))),
        ];
    }
}
