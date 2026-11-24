<?php

declare(strict_types=1);

namespace App\Services\Import\Amtelco;

/**
 * What a directory import did, or would do: one line per Amtelco
 * directory with where it went and how many entries it touched.
 */
class DirectoryImportReport
{
    /** @var array<int, array{directory: string, target: string, created: int, updated: int, unchanged: int}> */
    private array $rows = [];

    public function __construct(public readonly bool $dryRun) {}

    /**
     * @param  array{created: int, updated: int, unchanged: int}  $counts
     */
    public function directory(string $directory, string $target, array $counts): void
    {
        $this->rows[] = ['directory' => $directory, 'target' => $target] + $counts;
    }

    /**
     * @return array<int, array{directory: string, target: string, created: int, updated: int, unchanged: int}>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return array<string, array{directories: int, created: int, updated: int, unchanged: int}> destination kind => totals
     */
    public function totals(): array
    {
        $totals = [];
        foreach ($this->rows as $row) {
            $kind = match (true) {
                str_starts_with($row['target'], 'client') => 'Client directories',
                str_starts_with($row['target'], 'shared: '.DirectoryImporter::PROVIDERS_DIRECTORY) => 'Provider index (shared)',
                str_starts_with($row['target'], 'shared') => 'Unattached shared directories',
                default => 'Skipped (no listings)',
            };
            $totals[$kind] ??= ['directories' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0];
            $totals[$kind]['directories']++;
            foreach (['created', 'updated', 'unchanged'] as $k) {
                $totals[$kind][$k] += $row[$k];
            }
        }

        return $totals;
    }

    public function writeCsv(string $path): void
    {
        $fh = fopen($path, 'w');
        if ($fh === false) {
            throw new \RuntimeException("Cannot write report to {$path}");
        }
        fputcsv($fh, ['amtelco_directory', 'imported_to', 'created', 'updated', 'unchanged'], escape: '');
        foreach ($this->rows as $row) {
            fputcsv($fh, array_values($row), escape: '');
        }
        fclose($fh);
    }
}
