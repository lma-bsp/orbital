<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Import\Amtelco\AmtelcoImporter;
use App\Services\Import\Amtelco\ImportReport;
use Illuminate\Console\Command;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Telescope\Telescope;
use Throwable;

/**
 * Create Orbital clients from an Amtelco Infinity client export.
 *
 * Run it with --dry-run first and read the report: it lists every
 * account with what would happen to it and why, so the exclusion rules
 * can be checked before anything is written. Re-running is safe; see
 * AmtelcoImporter for what a re-run changes.
 */
class ImportAmtelcoCommand extends Command
{
    protected $signature = 'orbital:import-amtelco
        {path : The Amtelco client export workbook (.xlsx)}
        {--dry-run : Read everything and report, but write nothing}
        {--refresh : Also overwrite name, greeting, operator note and passcode on clients this import created before}
        {--include=* : Account numbers to import even if a rule would skip them}
        {--exclude=* : Account numbers to skip even if no rule would}
        {--timezone=America/New_York : Timezone for newly created clients}
        {--tier=free : Service tier for newly created clients}
        {--report= : Where to write the per-account CSV report (default: storage/app/amtelco-import-<time>.csv)}';

    protected $description = 'Create clients from an Amtelco Infinity client export workbook';

    public function handle(AmtelcoImporter $importer): int
    {
        $path = (string) $this->argument('path');
        $timezone = (string) $this->option('timezone');
        $tier = (string) $this->option('tier');

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            $this->error("Unknown timezone '{$timezone}'.");

            return self::FAILURE;
        }
        if (! in_array($tier, Team::TIERS, true)) {
            $this->error("Unknown tier '{$tier}'. Use one of: ".implode(', ', Team::TIERS).'.');

            return self::FAILURE;
        }

        $include = $this->accountList('include');
        $exclude = $this->accountList('exclude');
        if ($include === null || $exclude === null) {
            return self::FAILURE;
        }

        // Telescope and Pulse hold what they record in memory until a
        // command ends; across thousands of clients that is the whole
        // memory limit, and none of it is worth keeping.
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }
        if (class_exists(Pulse::class)) {
            Pulse::stopRecording();
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info($dryRun ? 'Dry run: nothing will be written.' : 'Importing.');

        try {
            $report = $importer->import(
                $path,
                dryRun: $dryRun,
                refresh: (bool) $this->option('refresh'),
                forceInclude: $include,
                forceExclude: $exclude,
                timezone: $timezone,
                tier: $tier,
                progress: fn (string $message) => $this->line("  {$message}…"),
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $reportPath = (string) ($this->option('report') ?: storage_path('app/amtelco-import-'.now()->format('Ymd-His').($dryRun ? '-dry-run' : '').'.csv'));
        $report->writeCsv($reportPath);

        $this->summarize($report);
        $this->newLine();
        $this->info("Per-account report: {$reportPath}");

        return self::SUCCESS;
    }

    private function summarize(ImportReport $report): void
    {
        $totals = $report->totals();
        $verb = $report->dryRun ? 'Would' : 'Did';

        $this->newLine();
        $this->table(['', 'Accounts'], [
            ["{$verb} create", $totals[ImportReport::CREATE]],
            ["{$verb} update", $totals[ImportReport::UPDATE]],
            ['Skipped', $totals[ImportReport::SKIP]],
            ['Conflicts (number already used by another client)', $totals[ImportReport::CONFLICT]],
            ['Flagged for review', $report->flaggedCount()],
        ]);

        if ($report->skipReasons() !== []) {
            $this->table(['Skip rule', 'Accounts'], collect($report->skipReasons())->map(fn ($n, $rule) => [$rule, $n])->values()->all());
        }

        $this->table(['Archived section', 'Accounts'], collect($report->sections())->map(fn ($n, $s) => [$s, $n])->values()->all());

        foreach ($report->accounts() as $row) {
            if ($row['action'] === ImportReport::CONFLICT) {
                $this->warn("Conflict: #{$row['account']} {$row['source_name']}: {$row['reason']}");
            }
        }
    }

    /**
     * @return array<int, int>|null
     */
    private function accountList(string $option): ?array
    {
        $numbers = [];
        foreach ((array) $this->option($option) as $value) {
            foreach (preg_split('/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) as $part) {
                if (! ctype_digit($part)) {
                    $this->error("--{$option} takes account numbers; got '{$part}'.");

                    return null;
                }
                $numbers[] = (int) $part;
            }
        }

        return $numbers;
    }
}
