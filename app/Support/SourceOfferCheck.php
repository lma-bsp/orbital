<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Is this build's AGPL section 13 source offer telling the truth?
 *
 * {@see Release} assembles the offer; this decides whether it can be
 * trusted. Two things make it a guess rather than an answer:
 *
 *   1. No ORBITAL_COMMIT. /source can only send people to a moving branch.
 *   2. The source URL is still the upstream default, but the build isn't a
 *      tagged upstream release. That is the shape of modified code pointing
 *      at unmodified source — a fork or a local build deployed somewhere.
 *
 * Read by `orbital:status` and the dashboard's `licensing` health card.
 * Whoever builds the image owns the source URL: the build scripts pass
 * ORBITAL_SOURCE_URL and ORBITAL_COMMIT, and the Helm chart's
 * licensing.sourceUrl can override the URL at runtime.
 */
final class SourceOfferCheck
{
    /**
     * Human-readable problems with the source offer; empty when it holds up.
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        $problems = [];

        if (Release::commit() === null) {
            $problems[] = 'ORBITAL_COMMIT is not set, so /source cannot link the exact code this build runs. Build the image with --build-arg ORBITAL_COMMIT=$(git rev-parse HEAD).';
        }

        if (self::pointsUpstream() && ! self::isTaggedRelease()) {
            $problems[] = 'The source URL is the upstream default but this build ('.Release::version().', channel '.Release::channel().') is not a tagged upstream release. Point ORBITAL_SOURCE_URL (Helm: licensing.sourceUrl) at the public repository this build came from.';
        }

        return $problems;
    }

    /**
     * Local development (`sail up`) has no external users to offer
     * source to, so the upstream default is acceptable there.
     */
    public static function isExempt(): bool
    {
        return app()->environment('local');
    }

    public static function pointsUpstream(): bool
    {
        return self::normalize(Release::sourceUrl()) === self::normalize(Release::UPSTREAM_SOURCE_URL);
    }

    /**
     * A tagged upstream release: a semver version on a non-dev channel.
     * The release workflow builds from v* tags, which set both.
     */
    public static function isTaggedRelease(): bool
    {
        return Release::channel() !== 'dev'
            && preg_match('/^v?\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', Release::version()) === 1;
    }

    private static function normalize(string $url): string
    {
        $url = strtolower(rtrim(trim($url), '/'));

        return str_ends_with($url, '.git') ? substr($url, 0, -4) : $url;
    }
}
