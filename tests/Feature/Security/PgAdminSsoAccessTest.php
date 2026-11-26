<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Passport\FirstPartyClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesOrbitalUsers;
use Tests\TestCase;

/**
 * pgAdmin's OIDC sign-in auto-approves only for super-admins and
 * holders of `tooling.pgadmin`; everyone else is refused outright
 * (not sent to the consent screen, which would let them approve).
 */
class PgAdminSsoAccessTest extends TestCase
{
    use CreatesOrbitalUsers;
    use RefreshDatabase;

    private function client(string $name): FirstPartyClient
    {
        return (new FirstPartyClient)->forceFill(['name' => $name]);
    }

    public function test_super_admin_skips_consent(): void
    {
        $admin = $this->makeUserWithTeamlessRole('super_admin');

        $this->assertTrue($this->client('pgadmin')->skipsAuthorization($admin, []));
    }

    public function test_staff_with_tooling_pgadmin_skips_consent(): void
    {
        $user = $this->makeUserWithTeamlessRole('dba');
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        Permission::findOrCreate('tooling.pgadmin', 'web');
        Role::findByName('dba', 'web')->givePermissionTo('tooling.pgadmin');
        $registrar->forgetCachedPermissions();

        $this->assertTrue($this->client('pgadmin')->skipsAuthorization($user->fresh(), []));
    }

    public function test_operator_without_the_permission_is_refused(): void
    {
        Permission::findOrCreate('tooling.pgadmin', 'web');
        $operator = $this->makeUserWithTeamlessRole('operator');

        $this->assertForbidden(fn () => $this->client('pgadmin')->skipsAuthorization($operator, []));
    }

    public function test_client_user_is_refused_even_when_the_permission_is_not_seeded(): void
    {
        $clientUser = $this->makeTenantUser();

        $this->assertForbidden(fn () => $this->client('pgadmin')->skipsAuthorization($clientUser, []));
    }

    public function test_plain_user_is_refused(): void
    {
        $this->assertForbidden(fn () => $this->client('pgadmin')->skipsAuthorization(User::factory()->create(), []));
    }

    public function test_unknown_client_goes_through_normal_consent(): void
    {
        $this->assertFalse($this->client('something-else')->skipsAuthorization(User::factory()->create(), []));
        $this->assertFalse($this->client('minio-console')->skipsAuthorization(User::factory()->create(), []));
    }

    private function assertForbidden(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected a 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
