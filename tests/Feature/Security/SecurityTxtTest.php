<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Services\Security\SecurityTxt;
use App\Services\Settings\SettingsRegistry;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\SuperAdminRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesOrbitalUsers;
use Tests\TestCase;

/**
 * Each deployment's own /.well-known/security.txt (RFC 9116), built from
 * Platform Settings → Security.
 */
class SecurityTxtTest extends TestCase
{
    use CreatesOrbitalUsers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_is_not_served_until_a_contact_is_set(): void
    {
        // Pointing a deployment's users at upstream would send reports
        // about the operator's own install to people who can't act on them.
        config()->set('orbital.security.contact', null);

        $this->get('/.well-known/security.txt')->assertNotFound();
    }

    public function test_it_is_public_and_renders_contact_policy_and_expiry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-26 12:00:00', 'UTC'));
        config()->set('app.url', 'https://orbital.example.com');
        config()->set('orbital.security.contact', 'security@example.com');
        config()->set('orbital.security.policy_url', 'https://example.com/security');

        $response = $this->get('/.well-known/security.txt')->assertOk();

        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=86400', (string) $response->headers->get('Cache-Control'));

        $this->assertSame(implode("\n", [
            'Contact: mailto:security@example.com',
            'Expires: 2027-05-25T12:00:00.000Z',
            'Policy: https://example.com/security',
            'Canonical: https://orbital.example.com/.well-known/security.txt',
            'Preferred-Languages: en',
        ])."\n", $response->getContent());
    }

    public function test_expiry_is_always_180_days_ahead(): void
    {
        config()->set('orbital.security.contact', 'https://example.com/report');
        $now = Carbon::parse('2030-01-01 00:00:00', 'UTC');

        $body = (string) app(SecurityTxt::class)->render($now);

        $this->assertStringContainsString('Contact: https://example.com/report', $body);
        $this->assertStringContainsString('Expires: 2030-06-30T00:00:00.000Z', $body);
        $this->assertStringNotContainsString('Policy:', $body);
    }

    public function test_canonical_is_left_out_on_a_plain_http_install(): void
    {
        config()->set('app.url', 'http://orbital.localhost:8080');
        config()->set('orbital.security.contact', 'security@example.com');

        $body = (string) app(SecurityTxt::class)->render();

        $this->assertStringNotContainsString('Canonical:', $body);
    }

    /**
     * @return array<string, array{mixed, ?string}>
     */
    public static function contacts(): array
    {
        return [
            'bare email' => ['security@example.com', 'mailto:security@example.com'],
            'mailto' => ['mailto:security@example.com', 'mailto:security@example.com'],
            'https page' => ['https://example.com/report', 'https://example.com/report'],
            'tel' => ['tel:+1-555-0100', 'tel:+1-555-0100'],
            'http is not allowed' => ['http://example.com/report', null],
            'line break cannot inject fields' => ["security@example.com\nContact: mailto:evil@example.net", null],
            'garbage' => ['not a contact', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('contacts')]
    public function test_contact_values_are_normalised_or_refused(mixed $value, ?string $expected): void
    {
        $this->assertSame($expected, SecurityTxt::contactUri($value));
    }

    public function test_a_non_https_policy_url_is_dropped(): void
    {
        config()->set('orbital.security.contact', 'security@example.com');
        config()->set('orbital.security.policy_url', 'http://example.com/security');

        $this->assertStringNotContainsString('Policy:', (string) app(SecurityTxt::class)->render());
    }

    public function test_both_settings_are_editable_in_the_security_section(): void
    {
        $security = SettingsRegistry::forSection('security');

        $this->assertSame('orbital.security.contact', $security['orbital.security.contact']['config_key'] ?? null);
        $this->assertSame('orbital.security.policy_url', $security['orbital.security.policy_url']['config_key'] ?? null);
    }

    public function test_about_page_links_security_txt_once_a_contact_is_set(): void
    {
        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(SuperAdminRoleSeeder::class);
        $admin = $this->makeUserWithTeamlessRole('super_admin');

        config()->set('orbital.security.contact', null);
        $this->actingAs($admin)
            ->get('/admin/about')
            ->assertOk()
            ->assertDontSee(route('security-txt'))
            ->assertSee('No security contact set');

        config()->set('orbital.security.contact', 'security@example.com');
        $this->actingAs($admin)
            ->get('/admin/about')
            ->assertOk()
            ->assertSee(route('security-txt'))
            ->assertSee('Report a security problem');
    }
}
