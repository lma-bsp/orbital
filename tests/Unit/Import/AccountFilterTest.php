<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use App\Services\Import\Amtelco\AccountFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AccountFilterTest extends TestCase
{
    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private static function account(array $overrides = []): array
    {
        return $overrides + [
            'Client Name' => 'ENT & ALLERGY ASSOC',
            'Client Status' => 'In Office',
            'Alpha Date' => '11/04/2026',
            'Text Msgs' => '0', 'Delivered' => '0', 'Voice Msgs' => '12', 'Played' => '3', 'Specials' => '0',
        ];
    }

    /**
     * @return array<string, array{array<string, string>, ?string}>
     */
    public static function accounts(): array
    {
        $idle = ['Alpha Date' => '01/01/1900', 'Voice Msgs' => '0', 'Played' => '0'];

        return [
            'real client' => [self::account(), null],
            // Utility words that are really medical specialties.
            'general surgery' => [self::account(['Client Name' => 'GENERAL SURGERY']), null],
            'internal medicine' => [self::account(['Client Name' => 'INTERNAL MED *NORWALK']), null],
            'copy template' => [self::account(['Client Name' => 'SET UP COPY ACCT']), AccountFilter::RULE_UTILITY_NAME],
            'test account' => [self::account(['Client Name' => 'Jerry Testing']), AccountFilter::RULE_UTILITY_NAME],
            'numbered test' => [self::account(['Client Name' => 'TEST2']), AccountFilter::RULE_UTILITY_NAME],
            'protestant is not a test' => [self::account(['Client Name' => 'PROTESTANT HOSPITAL']), null],
            'pbx extension' => [self::account(['Client Name' => 'ULTRACOM EXTENSION ONE']), AccountFilter::RULE_UTILITY_NAME],
            'dont use' => [self::account(['Client Name' => '>DONT USE']), AccountFilter::RULE_UTILITY_NAME],
            'number for a name' => [self::account(['Client Name' => '5291']), AccountFilter::RULE_UTILITY_NAME],
            'no name' => [self::account(['Client Name' => '']), AccountFilter::RULE_UTILITY_NAME],
            'placeholder' => [self::account(['Client Name' => 'Client 82641']), AccountFilter::RULE_PLACEHOLDER],
            'dropped' => [self::account(['Client Status' => 'DROP ACCT']), AccountFilter::RULE_DROPPED],
            'dead' => [self::account(['Client Status' => 'DEAD ACC']), AccountFilter::RULE_DROPPED],
            'cancelled' => [self::account(['Client Status' => 'cancel 8/15/']), AccountFilter::RULE_DROPPED],
            'acct closed abbreviated' => [self::account(['Client Status' => 'ACCT IS CLSD']), AccountFilter::RULE_DROPPED],
            'perm closed' => [self::account(['Client Status' => 'PERM CLSD']), AccountFilter::RULE_DROPPED],
            // A live state, not the account's lifecycle.
            'office closed right now' => [self::account(['Client Status' => 'OFC CLOSED']), null],
            'never used' => [self::account($idle), AccountFilter::RULE_NEVER_USED],
            'old alpha date but took messages' => [self::account(['Alpha Date' => '01/01/1900']), null],
        ];
    }

    /**
     * @param  array<string, string>  $account
     */
    #[DataProvider('accounts')]
    public function test_decides_which_accounts_are_clients(array $account, ?string $rule): void
    {
        $decision = (new AccountFilter)->decide($account);

        $this->assertSame($rule === null, $decision['include'], $decision['reason'] ?? 'included');
        $this->assertSame($rule, $decision['rule']);
    }

    public function test_ambiguous_closed_status_is_imported_but_flagged(): void
    {
        $decision = (new AccountFilter)->decide(self::account(['Client Status' => 'CLSD/READ']));

        $this->assertTrue($decision['include']);
        $this->assertStringContainsString('may mean the account is closed', $decision['flags'][0]);
    }

    public function test_in_house_lines_are_imported_but_flagged(): void
    {
        $decision = (new AccountFilter)->decide(self::account(['Client Name' => 'NCI CHECK IN LINE']));

        $this->assertTrue($decision['include']);
        $this->assertStringContainsString("answering service's own lines", $decision['flags'][0]);
    }
}
