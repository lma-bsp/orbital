<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Platform\CreateSuperAdmin;
use App\Models\User;
use Illuminate\Database\Seeder;

class FirstSuperAdminSeeder extends Seeder
{
    /**
     * Creates the first super-admin account for the platform operator.
     *
     * Configure via env:
     *   SUPER_ADMIN_EMAIL, SUPER_ADMIN_PASSWORD, SUPER_ADMIN_NAME
     */
    public function run(): void
    {
        $email = env('SUPER_ADMIN_EMAIL');
        $password = env('SUPER_ADMIN_PASSWORD');
        // `?:` rather than env()'s default: an empty value (a Secret's blank
        // `name` key) should fall back too, not create a nameless user.
        $name = env('SUPER_ADMIN_NAME') ?: config('orbital.platform_company', 'Orbital').' Admin';

        if (! $email || ! $password) {
            $this->command?->warn('Skipping FirstSuperAdminSeeder: SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD not set.');

            return;
        }

        // Same user + "Platform" team + role as `orbital:make-admin`. An
        // existing account is promoted rather than recreated, so a re-run
        // with the same env is harmless.
        $action = app(CreateSuperAdmin::class);
        $user = User::where('email', $email)->first();

        if ($user) {
            $action->promote($user);
        } else {
            $action->create($name, $email, $password);
        }

        $this->command?->info("Super-admin ready: {$email}");
    }
}
