<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Filament\Portal\Resources\RoleResource\Pages\CreateRole;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesOrbitalUsers;
use Tests\TestCase;

/**
 * A client's own roles never pass for platform roles, however the
 * cached `roles` relation was loaded, and the portal won't let a
 * client name a role after a platform one.
 */
class PlatformRoleIsolationTest extends TestCase
{
    use CreatesOrbitalUsers;
    use RefreshDatabase;

    public function test_client_role_named_super_admin_is_not_super_admin(): void
    {
        $user = $this->makeTenantUser();
        $teamId = (int) $user->current_team_id;
        $registrar = app(PermissionRegistrar::class);

        $registrar->setPermissionsTeamId($teamId);
        $fake = Role::create(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => $teamId]);
        $user->assignRole($fake);

        // Load the relation under the client's context, as every
        // portal request does via SetPermissionsTeamContext.
        $user->load('roles');
        $this->assertTrue($user->roles->contains('name', 'super_admin'));

        $this->assertFalse($user->isSuperAdmin());
        $this->assertFalse($user->hasAnyPlatformRole());
        $this->assertSame($teamId, $registrar->getPermissionsTeamId());
    }

    public function test_real_super_admin_is_seen_with_roles_loaded_under_a_client_team(): void
    {
        $admin = $this->makeUserWithTeamlessRole('super_admin');
        $team = $this->attachTenantMembership($admin);
        $registrar = app(PermissionRegistrar::class);

        $registrar->setPermissionsTeamId($team->id);
        $admin->load('roles');
        $this->assertFalse($admin->roles->contains('name', 'super_admin'));

        $this->assertTrue($admin->isSuperAdmin());
        $this->assertTrue($admin->hasAnyPlatformRole());
    }

    public function test_platform_role_checks_leave_the_loaded_relation_alone(): void
    {
        $user = $this->makeTenantUser();
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($user->current_team_id);
        $user->load('roles');

        $user->isSuperAdmin();
        $user->hasAnyPlatformRole();

        $this->assertTrue($user->relationLoaded('roles'));
        $this->assertTrue($user->roles->contains('name', 'client_user'));
    }

    public function test_portal_refuses_platform_role_names(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        Role::create(['name' => 'supervisor', 'guard_name' => 'web', 'team_id' => null]);

        $owner = $this->makeTenantUser();
        $this->actingAs($owner);
        $registrar->setPermissionsTeamId($owner->current_team_id);
        Filament::setCurrentPanel(Filament::getPanel('portal'));

        foreach (['super_admin', 'Super_Admin', 'supervisor', ' SUPERVISOR '] as $name) {
            Livewire::test(CreateRole::class)
                ->fillForm(['name' => $name])
                ->call('create')
                ->assertHasFormErrors(['name']);
        }

        Livewire::test(CreateRole::class)
            ->fillForm(['name' => 'night_shift'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('roles', ['name' => 'night_shift', 'team_id' => $owner->current_team_id]);
        $this->assertDatabaseMissing('roles', ['name' => 'super_admin', 'team_id' => $owner->current_team_id]);
    }
}
