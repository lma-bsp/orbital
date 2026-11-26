<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates a platform super-admin: the user, its "Platform" personal team
 * and the team-less `super_admin` role.
 *
 * Shared by `orbital:make-admin` and FirstSuperAdminSeeder so the two
 * ways of making the first login can't drift apart. Callers validate
 * their input; this only writes.
 */
class CreateSuperAdmin
{
    /**
     * Create a new super-admin. The caller has already checked that the
     * email is free and the super_admin role exists.
     */
    public function create(string $name, string $email, string $password): User
    {
        return DB::transaction(function () use ($name, $email, $password): User {
            // The User model's `hashed` cast hashes the plain password on save.
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'email_verified_at' => now(),
            ]);

            $this->promote($user);

            return $user;
        });
    }

    /**
     * Give an existing user the platform team (if it has no current team)
     * and the super_admin role. Safe to run more than once.
     */
    public function promote(User $user): void
    {
        DB::transaction(function () use ($user): void {
            if (! $user->currentTeam) {
                // "Platform" rather than Jetstream's "{name}'s Team" default.
                // Super-admins operate at the platform level, not inside a
                // tenant: the team exists only because BelongsToTeam needs
                // a current_team_id. "Platform" reads sensibly anywhere the
                // name surfaces (portal dashboard, tenant switcher, menus).
                $team = Team::forceCreate([
                    'user_id' => $user->id,
                    'name' => 'Platform',
                    'personal_team' => true,
                ]);

                $user->current_team_id = $team->id;
                $user->save();

                $user->teams()->syncWithoutDetaching([$team->id => ['role' => 'admin']]);
            }

            $registrar = app(PermissionRegistrar::class);
            $originalTeamId = $registrar->getPermissionsTeamId();
            $registrar->setPermissionsTeamId(null);

            try {
                $user->assignRole('super_admin');
            } finally {
                $registrar->setPermissionsTeamId($originalTeamId);
                $registrar->forgetCachedPermissions();
            }
        });
    }
}
