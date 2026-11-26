<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Detects credentials still sitting at the values `.env.example` ships,
 * or at a secret that was once committed to the repository.
 *
 * Those defaults exist so `sail up` gets a developer running in one
 * command. They become a liability the moment an install starts taking
 * real calls, and the failure is silent — nothing breaks, the door is
 * just open. SECURITY.md tells operators to check before going live, so
 * something has to actually check.
 *
 * Kept as a plain helper rather than folded into the status command so
 * it can be unit-tested and, later, surfaced on the system health
 * dashboard without duplicating the list.
 */
final class DefaultCredentials
{
    /**
     * Human label => a known-bad value: every default `.env.example`
     * ships, plus every secret that was ever committed to the repository.
     *
     * @return array<string, string>
     */
    public static function shipped(): array
    {
        return self::envExampleDefaults() + self::committedSecrets();
    }

    /**
     * Human label => the value `.env.example` ships.
     *
     * Add a row whenever `.env.example` grows a working default. A
     * default nobody knows about is worse than no default.
     *
     * @return array<string, string>
     */
    public static function envExampleDefaults(): array
    {
        return [
            'Database password (DB_PASSWORD)' => 'password',
            'Icecast source password' => 'changeme',
            'Icecast admin password' => 'changeme',
            'Icecast relay password' => 'changeme',
            'Icecast password' => 'changeme',
            'pgAdmin password' => 'password',
        ];
    }

    /**
     * Human label => a secret that was committed to this repository and
     * so is public, even after it was removed from the file. An install
     * still using one is as open as one that never set it.
     *
     * Mirrored in the Helm chart's `orbital.burnedCredentials` helper,
     * which refuses the same values at install time.
     *
     * @return array<string, string>
     */
    public static function committedSecrets(): array
    {
        return [
            // Old helm/orbital/values.yaml agentWorker.token default.
            'Agent worker token (AGENT_WORKER_TOKEN)' => AgentWorkerToken::DEV_TOKEN,
            // docker/asterisk/manager.conf, baked into the Asterisk image.
            'Asterisk AMI secret (ASTERISK_AMI_SECRET)' => '34bf07c4eedb1b4a471dd899a0692ce98e4ca59f0c8128c2',
            // docker/livekit/livekit.yaml and sip-config.yaml.
            'LiveKit API key (LIVEKIT_API_KEY)' => 'APIorbitaldev4a85eae7',
            'LiveKit API secret (LIVEKIT_API_SECRET)' => 'f4eca5232734d8d514da60b9a0f9c7c27b4b8473c550a442',
            'Valkey password (REDIS_PASSWORD)' => '8b630691e6e6c2292489d353cbfbccdbdd6073cbcf680f72',
        ];
    }

    /**
     * Current values, resolved the same way the app resolves them.
     *
     * @return array<string, mixed>
     */
    private static function current(): array
    {
        return [
            'Database password (DB_PASSWORD)' => config('database.connections.pgsql.password'),
            'Icecast source password' => env('ICECAST_SOURCE_PASSWORD'),
            'Icecast admin password' => env('ICECAST_ADMIN_PASSWORD'),
            'Icecast relay password' => env('ICECAST_RELAY_PASSWORD'),
            'Icecast password' => env('ICECAST_PASSWORD'),
            'pgAdmin password' => env('PGADMIN_DEFAULT_PASSWORD'),
            'Agent worker token (AGENT_WORKER_TOKEN)' => config('services.agent_worker.token'),
            'Asterisk AMI secret (ASTERISK_AMI_SECRET)' => config('telephony.asterisk.ami.secret'),
            'LiveKit API key (LIVEKIT_API_KEY)' => config('telephony.livekit.local.api_key'),
            'LiveKit API secret (LIVEKIT_API_SECRET)' => config('telephony.livekit.local.api_secret'),
            'Valkey password (REDIS_PASSWORD)' => config('database.redis.default.password'),
        ];
    }

    /**
     * Labels of credentials still at their shipped default.
     *
     * Compares values rather than merely checking that something is set:
     * an operator who rotated four of five secrets needs to be told
     * about the fifth, not handed a clean bill of health.
     *
     * @return array<int, string>
     */
    public static function unrotated(): array
    {
        $current = self::current();
        $unrotated = [];

        foreach (self::shipped() as $label => $shipped) {
            $value = $current[$label] ?? null;

            if (is_string($value) && $value !== '' && hash_equals($shipped, $value)) {
                $unrotated[] = $label;
            }
        }

        return $unrotated;
    }
}
