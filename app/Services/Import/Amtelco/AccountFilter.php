<?php

declare(strict_types=1);

namespace App\Services\Import\Amtelco;

/**
 * Decides which Amtelco accounts are real clients.
 *
 * An Amtelco system accumulates decades of accounts that aren't
 * customers: copy templates, test accounts, operator break accounts,
 * dropped clients, and numbers that were set up and never took a call.
 * These rules, tuned on NCI's export, skip them.
 *
 * Two things the tuning taught:
 *
 *   - Name keywords have to be narrow. "GENERAL" and "INTERNAL" look
 *     like utility words but mostly mean GENERAL SURGERY and INTERNAL
 *     MED, which are real practices.
 *   - "Client Status" is a live state field ("In Office", "At Lunch",
 *     "OFC CLOSED"), not an account lifecycle field. Only phrasings
 *     that clearly mean the account is finished exclude it. Bare
 *     "CLOSED" and similar is flagged for a person to look at instead.
 *
 * Every decision carries a reason so the dry-run report can be checked
 * line by line, and the command's --include/--exclude options override
 * the rules for specific accounts.
 */
class AccountFilter
{
    public const RULE_UTILITY_NAME = 'utility-name';

    public const RULE_PLACEHOLDER = 'placeholder-name';

    public const RULE_DROPPED = 'dropped-status';

    public const RULE_NEVER_USED = 'never-used';

    public const RULE_FORCED_EXCLUDE = 'excluded-by-option';

    /** Names that mark an account as an internal or utility account. */
    private const UTILITY_NAME_PATTERNS = [
        '/^SET ?UP COPY ACCT$/i',
        '/^DO A (MESSAGE )?SEARCH$/i',
        '/\bTEST(ING|S)?\b|TEST\d|^TEST_|JOEYTEST/i',
        '/^(DEMO|DUMMY|DUMBY) ACC(OUN)?T$/i',
        '/^EMPLOYEE BREAK ACCOUNT$/i',
        '/^VOICE ?MAIL BOX SET ?UP$/i',
        '/^UPDATE THESE ACCTS$/i',
        '/^THIS ACCOUNT$/i',
        '/^HANDLE THIS ACCOUNT NOW!?$/i',
        '/^>?\s*DO ?N\'?O?T (USE|SAY ANYTHING)/i',
        // The answering service's own phone system, not clients.
        '/^ULTRACOM EXTENSION\b/i',
        '/^EXTENSION \d+$/i',
        '/^PBX TO OPERATOR$/i',
        // Generic mailboxes and pager accounts with no client named.
        '/^\**\s*VOICE ?MAIL ACC(OUN)?T\b/i',
        '/^(AUTOPAGE|PAGER) ACC(OUN)?T$/i',
        '/^OPEN VOICE ?MAIL$/i',
        '/^NCI D-I-D REMOTE LOGIN$/i',
        '/^LOGGER ACCOUNT$/i',
        '/^CLOSED ACCOUNT$/i',
        '/^WRONG NUMBER ACCOUNT$/i',
    ];

    /**
     * Statuses that mean the account itself is finished: DROP ACCT, DEAD
     * ACC, CANCEL SVC, RETIRED, PERM CLSD, ACCT IS CLSD, ACCCT CLOSED,
     * CLOSED ACCT, ...
     */
    private const DROPPED_STATUS_PATTERN = '/\bDROP(PED)?\b|\bDEAD\b|CANCEL|\bTERMINATED\b|\bRETIRED\b|\bPERM\.? ?(CLOSED|CLOSD|CLSD)\b|\bAC+(N|OU?N)?T?\.?( IS)? *(CLOSED|CLSD)\b|\b(CLOSED|CLSD) ACC/i';

    /**
     * Names that look like the answering service's own lines rather
     * than a client: its offices, staff, check-in and job lines. Not
     * excluded automatically, since some may be services it sells;
     * flagged so a person decides.
     */
    private const IN_HOUSE_NAME_PATTERN = '/\bN\.?C\.?I\b|CHECK ?IN LINE|OFFICE LINE|JOB LINE|EMPLOYMENT|HUMAN RESOURCES|^BILLING OFFICE|DRILL LINE|^[A-Z]+\'S OFFICE$|^>?\s*DO ?N\'?O?T TOUCH/i';

    /** Statuses that might mean the account is finished, or might be a current state. */
    private const AMBIGUOUS_STATUS_PATTERN = '/\bCLOS(ED|D)\b|\bCLSD\b/i';

    /** Message counters that are all zero on an account that never took a call. */
    private const ACTIVITY_COLUMNS = ['Text Msgs', 'Delivered', 'Voice Msgs', 'Played', 'Specials'];

    /**
     * @param  array<string, string>  $account  one row of the Accounts tab
     * @return array{include: bool, rule: ?string, reason: ?string, flags: array<int, string>}
     */
    public function decide(array $account): array
    {
        $name = trim((string) ($account['Client Name'] ?? ''));
        $status = trim((string) ($account['Client Status'] ?? ''));
        $flags = [];

        if (preg_match(self::AMBIGUOUS_STATUS_PATTERN, $status) && ! preg_match(self::DROPPED_STATUS_PATTERN, $status)) {
            $flags[] = "status '{$status}' may mean the account is closed";
        }

        if (preg_match(self::IN_HOUSE_NAME_PATTERN, $name)) {
            $flags[] = 'name looks like one of the answering service\'s own lines';
        }

        if ($name === '' || preg_match('/^\d+$/', $name)) {
            return $this->exclude(self::RULE_UTILITY_NAME, $name === '' ? 'no name' : "name is only a number ({$name})", $flags);
        }

        foreach (self::UTILITY_NAME_PATTERNS as $pattern) {
            if (preg_match($pattern, $name)) {
                return $this->exclude(self::RULE_UTILITY_NAME, "internal/utility name '{$name}'", $flags);
            }
        }

        if (preg_match('/^Client \d+$/i', $name)) {
            return $this->exclude(self::RULE_PLACEHOLDER, "placeholder name '{$name}'", $flags);
        }

        if ($status !== '' && preg_match(self::DROPPED_STATUS_PATTERN, $status)) {
            return $this->exclude(self::RULE_DROPPED, "status '{$status}'", $flags);
        }

        if ($this->neverUsed($account)) {
            return $this->exclude(self::RULE_NEVER_USED, 'alpha date 01/01/1900 and no messages', $flags);
        }

        return ['include' => true, 'rule' => null, 'reason' => null, 'flags' => $flags];
    }

    /**
     * @param  array<string, string>  $account
     */
    private function neverUsed(array $account): bool
    {
        if (! str_ends_with(trim((string) ($account['Alpha Date'] ?? '')), '/1900')) {
            return false;
        }

        foreach (self::ACTIVITY_COLUMNS as $column) {
            if ((int) ($account[$column] ?? 0) !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $flags
     * @return array{include: bool, rule: string, reason: string, flags: array<int, string>}
     */
    private function exclude(string $rule, string $reason, array $flags): array
    {
        return ['include' => false, 'rule' => $rule, 'reason' => $reason, 'flags' => $flags];
    }
}
