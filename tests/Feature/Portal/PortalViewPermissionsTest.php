<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Filament\Portal\Pages\CallHistory;
use App\Models\CallLog;
use App\Models\MessageThread;
use App\Models\Team;
use App\Models\User;
use App\Services\Clients\ClientPermissionGatekeeper;
use App\Services\Clients\ClientProvisioner;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\SuperAdminRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The portal.view_* permissions offered on the portal Roles page are
 * enforced: unticking one closes the page or action it names, the
 * team owner keeps everything inside the client's permission ceiling,
 * and a user with every permission is unaffected.
 */
class PortalViewPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $owner;

    private User $member;

    private CallLog $call;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(SuperAdminRoleSeeder::class);

        $this->owner = User::factory()->create();
        $this->team = Team::forceCreate([
            'user_id' => $this->owner->id,
            'name' => 'Acme Plumbing',
            'personal_team' => false,
        ]);
        app(ClientProvisioner::class)->provision($this->team, $this->owner);
        $this->owner->forceFill(['current_team_id' => $this->team->id])->save();

        $this->member = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->member->teams()->attach($this->team->id, ['role' => 'member']);
        $this->withTeamContext(fn () => $this->member->assignRole(ClientProvisioner::ROLE_CLIENT_USER));

        $this->call = CallLog::create([
            'team_id' => $this->team->id,
            'unique_id' => 'perm-test-1',
            'direction' => 'inbound',
            'status' => 'completed',
            'from_number' => '+15555550111',
            'to_number' => '+15555550222',
            'started_at' => now(),
            'transcript' => "Caller: Hello\nAgent: Hi there",
            'recording_path' => 'recordings/perm-test-1.wav',
        ]);

        MessageThread::create([
            'team_id' => $this->team->id,
            'remote_address' => '+15551110000',
            'protocol' => 'sms',
            'status' => MessageThread::STATUS_NEW,
            'last_message_at' => now(),
        ]);
    }

    // ---- a user with every view permission ----------------------------

    public function test_member_with_all_view_permissions_sees_everything(): void
    {
        $this->actingAs($this->member);

        $this->get('/portal')->assertOk()->assertSee('+15555550111')
            ->assertSee('Call History')->assertSee('Text Messages');
        $this->get('/portal/calls')->assertOk();
        $this->get('/portal/texts')->assertOk()->assertSee('+15551110000');

        $this->callHistory()
            ->assertTableActionVisible('transcript', $this->call)
            ->assertTableActionVisible('play', $this->call);

        Filament::setCurrentPanel('portal');
        config(['telephony.recording.storage_disk' => 'local']);
        $html = view('filament.components.recording-player', ['record' => $this->call])->render();
        $this->assertStringContainsString('perm-test-1.wav', $html);
    }

    // ---- each permission removed ----------------------------------------

    public function test_without_view_calls_call_history_is_forbidden_and_hidden(): void
    {
        $this->revokeFromMember('portal.view_calls');
        $this->actingAs($this->member);

        $this->get('/portal/calls')->assertForbidden();
        $this->get('/portal')->assertOk()
            ->assertDontSee('Call History')
            // The latest-activity list names callers.
            ->assertDontSee('+15555550111');
    }

    public function test_without_view_messages_texts_are_forbidden_and_hidden(): void
    {
        $this->revokeFromMember('portal.view_messages');
        $this->actingAs($this->member);

        $this->get('/portal/texts')->assertForbidden();
        $this->get('/portal')->assertOk()->assertDontSee('Text Messages');
    }

    public function test_without_view_recordings_play_is_hidden_and_refused(): void
    {
        $this->revokeFromMember('portal.view_recordings');
        $this->actingAs($this->member);

        $this->get('/portal/calls')->assertOk();

        $this->callHistory()
            ->assertTableActionHidden('play', $this->call)
            // The transcript is call detail, so it rides on view_calls.
            ->assertTableActionVisible('transcript', $this->call);

        // Not just hidden: the action itself refuses this user, so a
        // crafted Livewire call can't mount it either.
        $action = $this->callHistory()->instance()->getTable()->getAction('play');
        $action->record($this->call);
        $this->assertFalse($action->isAuthorized());
    }

    public function test_recording_player_renders_no_url_on_the_portal_without_view_recordings(): void
    {
        $this->revokeFromMember('portal.view_recordings');
        $this->actingAs($this->member);
        Filament::setCurrentPanel('portal');
        config(['telephony.recording.storage_disk' => 'local']);

        $html = view('filament.components.recording-player', ['record' => $this->call])->render();

        $this->assertStringNotContainsString('perm-test-1.wav', $html);
        $this->assertStringContainsString('No recording available', $html);
    }

    public function test_without_view_home_the_panel_root_redirects_to_an_open_page(): void
    {
        $this->revokeFromMember('portal.view_home');
        $this->actingAs($this->member);

        $this->get('/portal')->assertRedirect('/portal/calls');
        $this->get('/portal/calls')->assertOk()->assertDontSee('Overview');
    }

    public function test_without_any_view_permission_the_user_gets_a_clear_page(): void
    {
        $this->setMemberPermissions([]);
        $this->actingAs($this->member);

        $this->get('/portal')->assertRedirect('/portal/no-access');
        $this->get('/portal/no-access')->assertOk()
            ->assertSee('Your account has no portal pages yet')
            ->assertDontSee('+15555550111');
        $this->get('/portal/calls')->assertForbidden();
        $this->get('/portal/texts')->assertForbidden();
        $this->get('/portal/roles')->assertForbidden();
    }

    // ---- team owner -------------------------------------------------------

    public function test_team_owner_keeps_every_page_even_when_roles_lose_them(): void
    {
        // The owner's own role loses every view permission; the owner
        // fallback keeps the portal open inside the ceiling.
        $this->withTeamContext(function () {
            $admin = Role::where('team_id', $this->team->id)
                ->where('name', ClientProvisioner::ROLE_CLIENT_ADMIN)->firstOrFail();
            app(ClientPermissionGatekeeper::class)
                ->syncRolePermissions($this->team, $admin, ['portal.manage_users', 'portal.manage_roles']);
        });
        $this->actingAs($this->owner);

        $this->get('/portal')->assertOk()->assertSee('+15555550111');
        $this->get('/portal/calls')->assertOk();
        $this->get('/portal/texts')->assertOk();
        $this->callHistory()->assertTableActionVisible('play', $this->call);
    }

    public function test_team_owner_fallback_respects_the_platform_ceiling(): void
    {
        // The platform operator takes recordings off this client's
        // ceiling: nobody in the client can play them, owner included.
        $this->actingAs($this->superAdmin());
        app(ClientPermissionGatekeeper::class)->setTenantAllowList($this->team, [
            'portal.view_home', 'portal.view_calls', 'portal.view_messages',
            'portal.manage_users', 'portal.manage_roles',
        ]);

        $this->actingAs($this->owner);
        $this->callHistory()->assertTableActionHidden('play', $this->call);

        $this->actingAs($this->member);
        $this->callHistory()->assertTableActionHidden('play', $this->call);
    }

    public function test_platform_role_checks_leave_client_permission_checks_intact(): void
    {
        // The portal user menu asks hasAnyPlatformRole()/isSuperAdmin()
        // mid-render; that must not leave a team-less `roles` relation
        // behind for the portal.* checks that follow.
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->team->id);
        $member = $this->member->fresh();

        $this->assertTrue($member->can('portal.view_calls'));
        $this->assertFalse($member->hasAnyPlatformRole());
        $this->assertFalse($member->isSuperAdmin());
        $this->assertTrue($member->can('portal.view_calls'));
    }

    // ---- helpers ------------------------------------------------------------

    private function callHistory()
    {
        Filament::setCurrentPanel('portal');
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->team->id);

        return Livewire::test(CallHistory::class);
    }

    private function revokeFromMember(string $permission): void
    {
        $this->setMemberPermissions(array_values(array_diff(
            ClientProvisioner::DEFAULT_TENANT_USER_PERMISSIONS,
            [$permission],
        )));
    }

    /** @param  array<int, string>  $permissions */
    private function setMemberPermissions(array $permissions): void
    {
        $this->withTeamContext(function () use ($permissions) {
            $role = Role::where('team_id', $this->team->id)
                ->where('name', ClientProvisioner::ROLE_CLIENT_USER)->firstOrFail();
            app(ClientPermissionGatekeeper::class)->syncRolePermissions($this->team, $role, $permissions);
        });
    }

    private function withTeamContext(callable $callback): void
    {
        $registrar = app(PermissionRegistrar::class);
        $original = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($this->team->id);

        try {
            $callback();
        } finally {
            $registrar->setPermissionsTeamId($original);
            $registrar->forgetCachedPermissions();
            $this->member->unsetRelation('roles')->unsetRelation('permissions');
            $this->owner->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        $user->assignRole(Role::where('name', 'super_admin')->whereNull('team_id')->firstOrFail());
        $registrar->forgetCachedPermissions();

        return $user;
    }
}
