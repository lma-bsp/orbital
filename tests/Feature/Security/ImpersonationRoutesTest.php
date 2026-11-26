<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrbitalUsers;
use Tests\TestCase;

/**
 * POST /impersonate/stop must reach stop(), not start() with a user
 * id of "stop" (which route-model-binds to a 404).
 */
class ImpersonationRoutesTest extends TestCase
{
    use CreatesOrbitalUsers;
    use RefreshDatabase;

    public function test_stop_route_resolves_to_stop_action(): void
    {
        $route = app('router')->getRoutes()->match(
            request()->create('/impersonate/stop', 'POST')
        );

        $this->assertSame('impersonate.stop', $route->getName());
    }

    public function test_super_admin_can_start_and_stop_impersonating(): void
    {
        $admin = $this->makeUserWithTeamlessRole('super_admin');
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->post("/impersonate/{$target->id}")
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($target);
        $this->assertSame($admin->id, session('impersonator_id'));

        $this->post('/impersonate/stop')->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session('impersonator_id'));
    }

    public function test_non_numeric_user_segment_is_not_a_start_route(): void
    {
        $admin = $this->makeUserWithTeamlessRole('super_admin');

        $this->actingAs($admin)->post('/impersonate/someone')->assertNotFound();
        $this->assertNull(session('impersonator_id'));
    }
}
