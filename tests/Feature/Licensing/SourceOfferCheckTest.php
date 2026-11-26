<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Services\Health\HealthCheck;
use App\Services\Health\SystemHealthService;
use App\Services\Telephony\AsteriskAmiService;
use App\Services\Telephony\LiveKitConfigService;
use App\Services\Telephony\RtpengineService;
use App\Support\Release;
use App\Support\SourceOfferCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /source must point at the code a build actually runs. These pin the
 * warnings that fire when it can't: no recorded commit, or the upstream
 * URL on a build that isn't an upstream release.
 */
class SourceOfferCheckTest extends TestCase
{
    use RefreshDatabase;

    private function build(?string $commit, string $sourceUrl, string $version, string $channel): void
    {
        config()->set('orbital.commit', $commit);
        config()->set('orbital.source_url', $sourceUrl);
        config()->set('orbital.version', $version);
        config()->set('orbital.release_channel', $channel);
    }

    public function test_a_tagged_upstream_release_with_a_commit_is_clean(): void
    {
        $this->build(str_repeat('a', 40), Release::UPSTREAM_SOURCE_URL, '1.4.2', 'stable');

        $this->assertSame([], SourceOfferCheck::problems());
    }

    public function test_a_fork_build_with_its_own_url_and_commit_is_clean(): void
    {
        // A dev version is fine when the URL names the fork it came from.
        $this->build(str_repeat('b', 40), 'https://github.com/someone/orbital', 'dev', 'dev');

        $this->assertSame([], SourceOfferCheck::problems());
    }

    public function test_a_missing_commit_is_flagged(): void
    {
        $this->build(null, 'https://github.com/someone/orbital', '1.4.2', 'stable');

        $problems = SourceOfferCheck::problems();

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('ORBITAL_COMMIT', $problems[0]);
    }

    public function test_the_upstream_url_on_a_dev_build_is_flagged(): void
    {
        // The k3d case: a local build of unpushed commits, deployed with
        // the chart's upstream default.
        $this->build(str_repeat('c', 40), Release::UPSTREAM_SOURCE_URL.'.git/', 'dev-20261124-121057', 'stable');

        $problems = SourceOfferCheck::problems();

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('upstream default', $problems[0]);
    }

    public function test_the_upstream_url_on_the_dev_channel_is_flagged_even_with_a_semver(): void
    {
        $this->build(str_repeat('d', 40), Release::UPSTREAM_SOURCE_URL, '1.4.2', 'dev');

        $this->assertCount(1, SourceOfferCheck::problems());
    }

    public function test_a_blank_source_url_counts_as_upstream(): void
    {
        // The image's ORBITAL_SOURCE_URL build arg defaults to empty,
        // which falls back to upstream.
        $this->build(null, '', 'dev', 'stable');

        $this->assertCount(2, SourceOfferCheck::problems());
    }

    public function test_health_card_warns_in_the_platform_category(): void
    {
        $this->build(null, Release::UPSTREAM_SOURCE_URL, 'dev', 'stable');

        $check = $this->licensingCard();

        $this->assertSame('licensing', $check->key);
        $this->assertSame('Platform', $check->category);
        $this->assertSame(HealthCheck::WARN, $check->status);
        $this->assertStringContainsString('ORBITAL_COMMIT', $check->message);
    }

    public function test_health_card_is_ok_for_a_clean_release(): void
    {
        $this->build(str_repeat('e', 40), Release::UPSTREAM_SOURCE_URL, '1.4.2', 'stable');

        $this->assertSame(HealthCheck::OK, $this->licensingCard()->status);
    }

    public function test_health_card_does_not_nag_local_development(): void
    {
        $this->build(null, Release::UPSTREAM_SOURCE_URL, 'dev', 'dev');
        $this->app['env'] = 'local';

        $this->assertSame(HealthCheck::OK, $this->licensingCard()->status);
    }

    public function test_orbital_status_reports_source_offer_problems(): void
    {
        $this->fakeTelephony();
        $this->build(null, Release::UPSTREAM_SOURCE_URL, 'dev', 'stable');

        $this->artisan('orbital:status')
            ->expectsOutputToContain('Source offer: /source may not point at the code this build runs')
            ->expectsOutputToContain('ORBITAL_COMMIT is not set')
            ->assertExitCode(0);
    }

    public function test_orbital_status_shows_the_source_link_when_clean(): void
    {
        $this->fakeTelephony();
        $this->build(str_repeat('f', 40), 'https://github.com/someone/orbital', '1.4.2', 'stable');

        $this->artisan('orbital:status')
            ->expectsOutputToContain('Source offer: https://github.com/someone/orbital/tree/'.str_repeat('f', 40))
            ->assertExitCode(0);
    }

    private function licensingCard(): HealthCheck
    {
        return (new \ReflectionMethod(SystemHealthService::class, 'checkLicensing'))
            ->invoke(app(SystemHealthService::class));
    }

    private function fakeTelephony(): void
    {
        $this->mock(AsteriskAmiService::class)->shouldReceive('getActiveChannels')->andReturn([]);
        $this->mock(LiveKitConfigService::class)->shouldReceive('healthCheck')->andReturn(true);
        $this->mock(RtpengineService::class)->shouldReceive('pingAll')->andReturn([]);
    }
}
