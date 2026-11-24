<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Import\Amtelco\DirectoryImporter;
use Illuminate\Console\Command;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Telescope\Telescope;
use Throwable;

/**
 * Load Amtelco directories into the directories of the clients that
 * orbital:import-amtelco created. Run that first. See
 * DirectoryImporter for where each directory goes.
 */
class ImportAmtelcoDirectoriesCommand extends Command
{
    protected $signature = 'orbital:import-amtelco-directories
        {path : The Amtelco directory export (nci_directories.json)}
        {--dry-run : Read everything and report, but write nothing}
        {--refresh : Also overwrite entries this import created before}
        {--report= : Where to write the per-directory CSV report (default: storage/app/amtelco-directories-<time>.csv)}';

    protected $description = 'Load Amtelco directories into client directories and shared directories';

    private function memoryLimitBytes(): int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }
        $bytes = (int) $limit;

        return match (strtolower(substr($limit, -1))) {
            'g' => $bytes * 1024 ** 3,
            'm' => $bytes * 1024 ** 2,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    }

    public function handle(DirectoryImporter $importer): int
    {
        // The whole export is decoded at once: several times its size on
        // disk as PHP arrays, which alone is most of the default 128 MB.
        if ($this->memoryLimitBytes() < 512 * 1024 * 1024) {
            ini_set('memory_limit', '512M');
        }

        // See ImportAmtelcoCommand: both buffer in memory until exit.
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }
        if (class_exists(Pulse::class)) {
            Pulse::stopRecording();
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info($dryRun ? 'Dry run: nothing will be written.' : 'Importing directories.');

        try {
            $report = $importer->import(
                (string) $this->argument('path'),
                dryRun: $dryRun,
                refresh: (bool) $this->option('refresh'),
                progress: fn (string $message) => $this->line("  {$message}…"),
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $path = (string) ($this->option('report') ?: storage_path('app/amtelco-directories-'.now()->format('Ymd-His').($dryRun ? '-dry-run' : '').'.csv'));
        $report->writeCsv($path);

        $verb = $dryRun ? 'would be ' : '';
        $this->newLine();
        $this->table(
            ['', 'Directories', "Entries {$verb}created", "Entries {$verb}updated", 'Already there'],
            collect($report->totals())->map(fn ($t, $kind) => [$kind, $t['directories'], $t['created'], $t['updated'], $t['unchanged']])->values()->all(),
        );
        $this->info("Per-directory report: {$path}");

        return self::SUCCESS;
    }
}
