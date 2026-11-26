<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DefaultCredentials;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SECURITY.md tells operators to run `orbital:status` before taking real
 * calls and promises it will flag credentials still at their shipped
 * defaults. A promise like that has to be true, and it silently stops
 * being true the moment someone adds a default to `.env.example` and
 * forgets the list here.
 */
class DefaultCredentialsTest extends TestCase
{
    public function test_a_shipped_default_is_reported(): void
    {
        config()->set('database.connections.pgsql.password', 'password');

        $this->assertContains(
            'Database password (DB_PASSWORD)',
            DefaultCredentials::unrotated(),
        );
    }

    public function test_a_rotated_credential_is_not_reported(): void
    {
        config()->set('database.connections.pgsql.password', 'a-real-secret');

        $this->assertNotContains(
            'Database password (DB_PASSWORD)',
            DefaultCredentials::unrotated(),
        );
    }

    public function test_an_unset_credential_is_not_reported(): void
    {
        // Not configured at all is a different situation from
        // "configured to the value we shipped", and only the second one
        // is a finding.
        config()->set('database.connections.pgsql.password', null);

        $this->assertNotContains(
            'Database password (DB_PASSWORD)',
            DefaultCredentials::unrotated(),
        );
    }

    public function test_every_shipped_default_in_env_example_is_covered(): void
    {
        // The list is maintained by hand, so this asserts it hasn't
        // drifted from the file it describes. A default nobody knows
        // about is worse than no default.
        $envExample = file_get_contents(base_path('.env.example'));

        $this->assertIsString($envExample);

        // Every literal default value we claim to know about should
        // still appear in .env.example. If one is gone, the entry here
        // is stale; if a new one appeared, add it.
        foreach (array_unique(DefaultCredentials::envExampleDefaults()) as $value) {
            $this->assertStringContainsString(
                '='.$value,
                $envExample,
                "DefaultCredentials lists '{$value}' but .env.example no longer ships it.",
            );
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function committedSecrets(): array
    {
        return [
            'agent worker token' => ['services.agent_worker.token', 'Agent worker token (AGENT_WORKER_TOKEN)'],
            'AMI secret' => ['telephony.asterisk.ami.secret', 'Asterisk AMI secret (ASTERISK_AMI_SECRET)'],
            'LiveKit key' => ['telephony.livekit.local.api_key', 'LiveKit API key (LIVEKIT_API_KEY)'],
            'LiveKit secret' => ['telephony.livekit.local.api_secret', 'LiveKit API secret (LIVEKIT_API_SECRET)'],
            'Valkey password' => ['database.redis.default.password', 'Valkey password (REDIS_PASSWORD)'],
        ];
    }

    #[DataProvider('committedSecrets')]
    public function test_a_committed_secret_is_reported(string $configKey, string $label): void
    {
        config()->set($configKey, DefaultCredentials::committedSecrets()[$label]);

        $this->assertContains($label, DefaultCredentials::unrotated());
    }

    #[DataProvider('committedSecrets')]
    public function test_a_rotated_committed_secret_is_not_reported(string $configKey, string $label): void
    {
        config()->set($configKey, bin2hex(random_bytes(24)));

        $this->assertNotContains($label, DefaultCredentials::unrotated());
    }

    public function test_every_committed_secret_has_a_check(): void
    {
        $this->assertEqualsCanonicalizing(
            array_keys(DefaultCredentials::committedSecrets()),
            array_column(self::committedSecrets(), 1),
        );
    }

    public function test_the_helm_chart_refuses_the_same_committed_secrets(): void
    {
        // The chart's orbital.burnedCredentials helper refuses these at
        // install time; the two lists drifting apart would leave one side
        // waving through a value the other knows is public.
        $helpers = file_get_contents(base_path('helm/orbital/templates/_helpers.tpl'));

        $this->assertIsString($helpers);

        foreach (DefaultCredentials::committedSecrets() as $label => $value) {
            $this->assertStringContainsString(
                '"'.$value.'"',
                $helpers,
                "{$label} is not refused by the Helm chart's orbital.burnedCredentials.",
            );
        }
    }
}
