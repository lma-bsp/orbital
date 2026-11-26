<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The shared bearer token the agent worker presents to the Laravel API.
 *
 * Whoever holds it gets the platform's AI provider keys and every
 * tenant's prompts, so every place that checks it goes through here.
 * The old Helm chart shipped a public default; outside local and
 * testing that value is treated as unset, so an install still running
 * it stops serving the worker API rather than serving it to anyone.
 */
final class AgentWorkerToken
{
    /** The development token the Helm chart used to ship as its default. */
    public const DEV_TOKEN = 'orbital-dev-agent-token';

    /**
     * The configured token, or null when there is none worth accepting.
     */
    public static function expected(): ?string
    {
        $token = (string) config('services.agent_worker.token');

        if ($token === '') {
            return null;
        }

        if (hash_equals(self::DEV_TOKEN, $token) && ! app()->environment('local', 'testing')) {
            return null;
        }

        return $token;
    }

    public static function matches(?string $bearer): bool
    {
        $expected = self::expected();

        return $expected !== null && hash_equals($expected, (string) $bearer);
    }
}
