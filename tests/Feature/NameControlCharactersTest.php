<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Filament\Resources\ClientResource\Pages\ManageClientCallQueues;
use App\Filament\Resources\ClientResource\Pages\ManageClientExtensions;
use App\Models\Extension;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\SuperAdminRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesOrbitalUsers;
use Tests\TestCase;

/**
 * Security review §8: names that end up in generated dialplan refuse
 * control characters at input.
 */
class NameControlCharactersTest extends TestCase
{
    use CreatesOrbitalUsers;
    use RefreshDatabase;

    public function test_profile_update_rejects_a_name_with_a_line_break(): void
    {
        $user = User::factory()->create(['name' => 'Jane']);

        try {
            app(UpdateUserProfileInformation::class)->update($user, [
                'name' => "Jane\nexten => 666,1,System(id)",
                'email' => $user->email,
            ]);
            $this->fail('Expected a validation error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }

        $this->assertSame('Jane', $user->fresh()->name);

        app(UpdateUserProfileInformation::class)->update($user, ['name' => "Jane O'Brien (Ops)", 'email' => $user->email]);
        $this->assertSame("Jane O'Brien (Ops)", $user->fresh()->name);
    }

    public function test_extension_and_queue_forms_reject_control_characters_and_bad_numbers(): void
    {
        Bus::fake();
        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(SuperAdminRoleSeeder::class);
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->makeUserWithTeamlessRole('super_admin'));
        $team = Team::factory()->create(['personal_team' => false]);

        Livewire::test(ManageClientExtensions::class, ['record' => $team->getKey()])
            ->callTableAction('create', data: ['number' => '1001,1,System(id)', 'type' => 'virtual', 'label' => "Front\ndesk"])
            ->assertHasTableActionErrors(['number', 'label']);

        Livewire::test(ManageClientExtensions::class, ['record' => $team->getKey()])
            ->callTableAction('create', data: ['number' => '1001', 'type' => 'virtual', 'label' => 'Front desk'])
            ->assertHasNoTableActionErrors();
        $this->assertTrue(Extension::withoutGlobalScopes()->where('team_id', $team->id)->where('number', '1001')->exists());

        Livewire::test(ManageClientCallQueues::class, ['record' => $team->getKey()])
            ->callTableAction('create', data: ['name' => "Support\r\n;--"])
            ->assertHasTableActionErrors(['name']);
    }
}
