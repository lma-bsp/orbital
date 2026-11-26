<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\AgentPersona;
use App\Models\DirectoryEntry;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\CreatesOrbitalUsers;
use Tests\TestCase;

/**
 * The team scope fails closed: a signed-in user who is neither a
 * super-admin nor platform staff and has no current team sees no
 * client's rows. Platform staff and unauthenticated contexts (jobs,
 * console, webhooks) keep the unfiltered view.
 */
class TenantScopeFailsClosedTest extends TestCase
{
    use CreatesOrbitalUsers;
    use RefreshDatabase;

    private Team $acme;

    private Team $globex;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();
        $this->acme = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Acme', 'personal_team' => false]);
        $this->globex = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Globex', 'personal_team' => false]);

        foreach ([$this->acme, $this->globex] as $team) {
            AgentPersona::withoutGlobalScopes()->create(['team_id' => $team->id, 'name' => "{$team->name} AI", 'role' => 'receptionist']);
            DirectoryEntry::withoutGlobalScopes()->create(['team_id' => $team->id, 'values' => ['name' => $team->name]]);
        }
    }

    public function test_teamless_non_staff_user_sees_nothing(): void
    {
        $user = User::factory()->create(['current_team_id' => null]);
        $this->assertNull($user->currentTeam);

        Auth::login($user);

        $this->assertSame(0, AgentPersona::count());
        $this->assertNull(AgentPersona::first());
        $this->assertSame(0, DirectoryEntry::count());
    }

    public function test_client_user_sees_only_their_client(): void
    {
        $user = $this->makeTenantUser();
        AgentPersona::withoutGlobalScopes()->create(['team_id' => $user->current_team_id, 'name' => 'Own AI', 'role' => 'receptionist']);

        Auth::login($user);

        $this->assertSame(['Own AI'], AgentPersona::pluck('name')->all());
        $this->assertSame(0, DirectoryEntry::count());
    }

    public function test_teamless_operator_keeps_the_cross_client_view(): void
    {
        Auth::login($this->makeUserWithTeamlessRole('operator'));

        $this->assertSame(2, AgentPersona::count());
        $this->assertSame(2, DirectoryEntry::count());
    }

    public function test_super_admin_keeps_the_cross_client_view(): void
    {
        Auth::login($this->makeUserWithTeamlessRole('super_admin'));

        $this->assertSame(2, AgentPersona::count());
        $this->assertSame(2, DirectoryEntry::count());
    }

    public function test_client_role_does_not_count_as_platform_staff(): void
    {
        // A user holding only a client-team role but with no current
        // team (e.g. removed from that client) is not platform staff.
        $user = $this->makeTenantUser();
        $user->forceFill(['current_team_id' => null])->save();
        $user->teams()->detach();
        $user->ownedTeams()->where('personal_team', true)->delete();
        $user = $user->fresh();
        $this->assertNull($user->currentTeam);

        Auth::login($user);

        $this->assertSame(0, AgentPersona::count());
    }

    public function test_unauthenticated_context_is_unfiltered(): void
    {
        Auth::logout();

        $this->assertSame(2, AgentPersona::count());
        $this->assertSame(2, DirectoryEntry::count());
    }
}
