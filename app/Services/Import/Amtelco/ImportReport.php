<?php

declare(strict_types=1);

namespace App\Services\Import\Amtelco;

/**
 * What an Amtelco import did, or would do in a dry run: one line per
 * account in the workbook, plus per-section archive counts.
 */
class ImportReport
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const SKIP = 'skip';

    public const CONFLICT = 'conflict';

    /**
     * @var array<int, array{account: int, source_name: string, name: string, action: string, reason: string, flags: array<int, string>, aliases: array<int, int>}>
     */
    private array $accounts = [];

    /** @var array<string, int> section => accounts archived */
    private array $sections = [];

    public function __construct(public readonly bool $dryRun) {}

    /**
     * @param  array<int, string>  $flags
     * @param  array<int, int>  $aliases
     */
    public function account(int $account, string $sourceName, string $name, string $action, string $reason = '', array $flags = [], array $aliases = []): void
    {
        $this->accounts[$account] = [
            'account' => $account,
            'source_name' => $sourceName,
            'name' => $name,
            'action' => $action,
            'reason' => $reason,
            'flags' => $flags,
            'aliases' => $aliases,
        ];
    }

    public function flag(int $account, string $flag): void
    {
        if (isset($this->accounts[$account])) {
            $this->accounts[$account]['flags'][] = $flag;
        }
    }

    public function archived(string $section): void
    {
        $this->sections[$section] = ($this->sections[$section] ?? 0) + 1;
    }

    /**
     * @return array<int, array{account: int, source_name: string, name: string, action: string, reason: string, flags: array<int, string>, aliases: array<int, int>}>
     */
    public function accounts(): array
    {
        return $this->accounts;
    }

    /**
     * @return array<string, int>
     */
    public function sections(): array
    {
        return $this->sections;
    }

    /**
     * @return array<string, int> action => count
     */
    public function totals(): array
    {
        $totals = [self::CREATE => 0, self::UPDATE => 0, self::SKIP => 0, self::CONFLICT => 0];
        foreach ($this->accounts as $row) {
            $totals[$row['action']]++;
        }

        return $totals;
    }

    /**
     * @return array<string, int> exclusion reason rule => count
     */
    public function skipReasons(): array
    {
        $counts = [];
        foreach ($this->accounts as $row) {
            if ($row['action'] === self::SKIP) {
                $rule = strtok($row['reason'], ':') ?: $row['reason'];
                $counts[$rule] = ($counts[$rule] ?? 0) + 1;
            }
        }
        arsort($counts);

        return $counts;
    }

    public function flaggedCount(): int
    {
        return count(array_filter($this->accounts, fn (array $row) => $row['flags'] !== []));
    }

    public function writeCsv(string $path): void
    {
        $fh = fopen($path, 'w');
        if ($fh === false) {
            throw new \RuntimeException("Cannot write report to {$path}");
        }

        fputcsv($fh, ['account_number', 'amtelco_name', 'orbital_name', 'action', 'reason', 'aliases', 'flags'], escape: '');
        foreach ($this->accounts as $row) {
            fputcsv($fh, [
                $row['account'],
                $row['source_name'],
                $row['name'],
                $row['action'],
                $row['reason'],
                implode(' ', $row['aliases']),
                implode('; ', $row['flags']),
            ], escape: '');
        }
        fclose($fh);
    }
}
