<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use App\Services\Clients\ClientProvisioner;
use App\Support\PermissionRegistrar;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\SuperAdminRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar as SpatiePermissionRegistrar;
use Tests\TestCase;

/**
 * App\Support\PermissionRegistrar only changes how the permission cache
 * is built, never what is in it.
 */
class PermissionRegistrarTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_the_registrar_the_app_uses(): void
    {
        $this->assertInstanceOf(PermissionRegistrar::class, app(SpatiePermissionRegistrar::class));
    }

    public function test_builds_exactly_the_cache_spatie_would(): void
    {
        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(SuperAdminRoleSeeder::class);
        $owner = User::factory()->create();
        foreach (range(1, 3) as $i) {
            $team = Team::forceCreate(['user_id' => $owner->id, 'name' => "Client {$i}", 'personal_team' => false]);
            app(ClientProvisioner::class)->provision($team, $owner);
        }

        $serialize = fn () => $this->getSerializedPermissionsForCache();
        $ours = \Closure::bind($serialize, new PermissionRegistrar(app('cache')), SpatiePermissionRegistrar::class)();
        $theirs = \Closure::bind($serialize, new SpatiePermissionRegistrar(app('cache')), SpatiePermissionRegistrar::class)();

        $this->assertNotEmpty($theirs['roles']);
        $this->assertEquals($theirs['alias'], $ours['alias']);
        $this->assertEqualsCanonicalizing($theirs['roles'], $ours['roles']);

        $byName = fn (array $cache) => collect($cache['permissions'])
            ->keyBy(fn ($p) => $p[array_search('name', $cache['alias'], true)])
            ->map(function ($p) {
                sort($p['r']);

                return $p;
            })
            ->sortKeys()
            ->all();
        // Spatie leaves out the role key for permissions that no role has.
        $normalize = fn (array $perms) => array_map(fn ($p) => $p + ['r' => []], $perms);
        $this->assertEquals($normalize($byName($theirs)), $normalize($byName($ours)));
    }

    public function test_permission_checks_still_work_per_client(): void
    {
        $this->seed(PermissionCatalogSeeder::class);
        $owner = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Client', 'personal_team' => false]);
        app(ClientProvisioner::class)->provision($team, $owner);

        $registrar = app(SpatiePermissionRegistrar::class);
        $registrar->forgetCachedPermissions();
        $registrar->setPermissionsTeamId($team->id);

        $this->assertTrue($owner->fresh()->hasPermissionTo('portal.manage_users'));
        $this->assertTrue($owner->fresh()->hasRole(ClientProvisioner::ROLE_CLIENT_ADMIN));
    }
}
