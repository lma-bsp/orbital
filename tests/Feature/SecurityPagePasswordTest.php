<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\Security;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class SecurityPagePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated_from_security_page(): void
    {
        $this->actingAs($user = User::factory()->create());

        Livewire::test(Security::class)
            ->set('currentPassword', 'password')
            ->set('newPassword', 'new-password-123')
            ->set('newPasswordConfirmation', 'new-password-123')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_wrong_current_password_is_rejected_on_security_page(): void
    {
        $this->actingAs($user = User::factory()->create());

        Livewire::test(Security::class)
            ->set('currentPassword', 'wrong-password')
            ->set('newPassword', 'new-password-123')
            ->set('newPasswordConfirmation', 'new-password-123')
            ->call('updatePassword');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
