<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\CreateAdminUser;
use App\Models\User;
use Database\Seeders\FirstSuperAdminSeeder;
use Database\Seeders\SuperAdminRoleSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * orbital:make-admin — the first login on a new install.
 */
class CreateAdminUserTest extends TestCase
{
    use RefreshDatabase;

    private const ARGS = ['--name' => 'Pat Admin', '--email' => 'pat@example.com', '--password' => 'a-strong-password'];

    private const NO_PASSWORD = ['--name' => 'Pat Admin', '--email' => 'pat@example.com'];

    public function test_creates_a_super_admin_on_a_seeded_database(): void
    {
        $this->seed(SuperAdminRoleSeeder::class);

        $this->artisan('orbital:make-admin', self::ARGS)->assertSuccessful();

        $user = User::where('email', 'pat@example.com')->firstOrFail();
        $this->assertNotNull($user->current_team_id);
        $this->assertTrue(DB::table('model_has_roles')->where('model_id', $user->id)->exists());
    }

    public function test_password_on_the_command_line_still_works_but_warns(): void
    {
        $this->seed(SuperAdminRoleSeeder::class);

        $this->artisan('orbital:make-admin', self::ARGS)
            ->expectsOutputToContain('Prefer --password-stdin')
            ->assertSuccessful();
    }

    public function test_an_unseeded_database_leaves_nothing_behind_and_says_why(): void
    {
        DB::table(config('permission.table_names.roles'))->delete();

        $this->artisan('orbital:make-admin', self::ARGS)
            ->expectsOutputToContain('has not been seeded')
            ->assertFailed();

        $this->assertFalse(User::where('email', 'pat@example.com')->exists());

        // ...so once seeded, the same command just works.
        $this->seed(SuperAdminRoleSeeder::class);
        $this->artisan('orbital:make-admin', self::ARGS)->assertSuccessful();
    }

    public function test_no_interaction_and_no_flags_fails_fast_with_guidance(): void
    {
        $this->seed(SuperAdminRoleSeeder::class);

        $this->artisan('orbital:make-admin', ['--no-interaction' => true])
            ->expectsOutputToContain(CreateAdminUser::NO_TERMINAL_MESSAGE)
            ->expectsOutputToContain('--password-stdin')
            ->assertExitCode(2);

        $this->assertSame(0, User::count());
    }

    public function test_stdin_that_is_not_a_terminal_never_prompts(): void
    {
        // `kubectl exec -i` without `-t`: input is "interactive" as far as
        // Symfony knows, but stdin is a pipe. This used to hang on `Name:`.
        $this->seed(SuperAdminRoleSeeder::class);
        $this->fakeStdin('', terminal: false);

        $this->artisan('orbital:make-admin', ['--email' => 'pat@example.com'])
            ->expectsOutputToContain(CreateAdminUser::NO_TERMINAL_MESSAGE)
            ->assertExitCode(2);

        $this->assertSame(0, User::count());
    }

    public function test_password_stdin_reads_the_piped_password(): void
    {
        $this->seed(SuperAdminRoleSeeder::class);
        $this->fakeStdin("piped-secret-password\n", terminal: false);

        $this->artisan('orbital:make-admin', self::NO_PASSWORD + ['--password-stdin' => true])
            ->doesntExpectOutputToContain('Prefer --password-stdin')
            ->assertSuccessful();

        $user = User::where('email', 'pat@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('piped-secret-password', $user->password));
    }

    public function test_password_stdin_with_nothing_piped_fails_with_guidance(): void
    {
        $this->seed(SuperAdminRoleSeeder::class);
        $this->fakeStdin('', terminal: false);

        $this->artisan('orbital:make-admin', self::NO_PASSWORD + ['--password-stdin' => true])
            ->expectsOutputToContain(CreateAdminUser::NO_TERMINAL_MESSAGE)
            ->assertExitCode(2);

        $this->assertSame(0, User::count());
    }

    public function test_password_stdin_on_a_terminal_prompts_instead_of_waiting(): void
    {
        $this->seed(SuperAdminRoleSeeder::class);
        $this->fakeStdin('', terminal: true);

        $this->artisan('orbital:make-admin', self::NO_PASSWORD + ['--password-stdin' => true])
            ->expectsQuestion('Password', 'typed-secret-password')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('typed-secret-password', User::where('email', 'pat@example.com')->value('password')));
    }

    public function test_a_terminal_prompts_for_missing_values(): void
    {
        $this->seed(SuperAdminRoleSeeder::class);
        $this->fakeStdin('', terminal: true);

        $this->artisan('orbital:make-admin')
            ->expectsQuestion('Name', 'Pat Admin')
            ->expectsQuestion('Email', 'pat@example.com')
            ->expectsQuestion('Password', 'typed-secret-password')
            ->assertSuccessful();

        $this->assertTrue(User::where('email', 'pat@example.com')->exists());
    }

    public function test_password_and_password_stdin_together_are_rejected(): void
    {
        $this->seed(SuperAdminRoleSeeder::class);

        $this->artisan('orbital:make-admin', self::ARGS + ['--password-stdin' => true])
            ->expectsOutputToContain('Use only one of --password, --password-stdin')
            ->assertExitCode(2);

        $this->assertSame(0, User::count());
    }

    public function test_generate_password_prints_a_24_character_password_once(): void
    {
        $this->seed(SuperAdminRoleSeeder::class);

        // PendingCommand can't hand back output lines, so call the kernel
        // directly to capture the printed password.
        $kernel = $this->app->make(Kernel::class);
        $kernel->call('orbital:make-admin', self::NO_PASSWORD + ['--generate-password' => true, '--no-interaction' => true]);
        $lines = explode("\n", trim($kernel->output()));
        $index = array_search('Generated password (shown once, store it now):', $lines, true);
        $this->assertNotFalse($index);
        $printed = $lines[$index + 1];

        $this->assertSame(24, strlen($printed));
        $this->assertTrue(Hash::check($printed, User::where('email', 'pat@example.com')->value('password')));
    }

    public function test_generate_password_with_password_is_rejected(): void
    {
        $this->artisan('orbital:make-admin', self::ARGS + ['--generate-password' => true])
            ->assertExitCode(2);

        $this->assertSame(0, User::count());
    }

    public function test_an_invalid_email_exits_2_and_leaves_nothing_behind(): void
    {
        $this->seed(SuperAdminRoleSeeder::class);

        $this->artisan('orbital:make-admin', ['--email' => 'not-an-email'] + self::ARGS)
            ->expectsOutputToContain('email')
            ->assertExitCode(2);

        $this->assertSame(0, User::count());
        $this->assertSame(0, DB::table('teams')->count());
    }

    public function test_a_short_password_exits_2_and_leaves_nothing_behind(): void
    {
        $this->seed(SuperAdminRoleSeeder::class);

        $this->artisan('orbital:make-admin', ['--password' => 'short'] + self::ARGS)
            ->expectsOutputToContain('at least 8 characters')
            ->assertExitCode(2);

        $this->assertSame(0, User::count());
        $this->assertSame(0, DB::table('teams')->count());
    }

    public function test_an_existing_email_is_still_a_failure(): void
    {
        $this->seed(SuperAdminRoleSeeder::class);
        User::factory()->create(['email' => 'pat@example.com']);

        $this->artisan('orbital:make-admin', self::ARGS)
            ->expectsOutputToContain('already exists')
            ->assertExitCode(1);
    }

    public function test_the_command_and_the_seeder_produce_the_same_admin(): void
    {
        $this->seed(SuperAdminRoleSeeder::class);

        $this->artisan('orbital:make-admin', self::ARGS)->assertSuccessful();

        putenv('SUPER_ADMIN_EMAIL=seeded@example.com');
        putenv('SUPER_ADMIN_PASSWORD=another-strong-password');
        putenv('SUPER_ADMIN_NAME=Sam Seeded');

        try {
            $this->seed(FirstSuperAdminSeeder::class);
        } finally {
            putenv('SUPER_ADMIN_EMAIL');
            putenv('SUPER_ADMIN_PASSWORD');
            putenv('SUPER_ADMIN_NAME');
        }

        $fromCommand = $this->describe(User::where('email', 'pat@example.com')->firstOrFail());
        $fromSeeder = $this->describe(User::where('email', 'seeded@example.com')->firstOrFail());

        $this->assertSame(['team' => 'Platform', 'pivot_role' => 'admin', 'roles' => ['super_admin']], $fromCommand);
        $this->assertSame($fromCommand, $fromSeeder);
        $this->assertTrue(Hash::check('another-strong-password', User::where('email', 'seeded@example.com')->value('password')));
    }

    public function test_the_seeder_promotes_an_existing_user_and_is_idempotent(): void
    {
        $this->seed(SuperAdminRoleSeeder::class);
        $existing = User::factory()->create(['email' => 'seeded@example.com']);

        putenv('SUPER_ADMIN_EMAIL=seeded@example.com');
        putenv('SUPER_ADMIN_PASSWORD=another-strong-password');

        try {
            $this->seed(FirstSuperAdminSeeder::class);
            $this->seed(FirstSuperAdminSeeder::class);
        } finally {
            putenv('SUPER_ADMIN_EMAIL');
            putenv('SUPER_ADMIN_PASSWORD');
        }

        $this->assertSame(1, User::where('email', 'seeded@example.com')->count());
        $this->assertSame(['team' => 'Platform', 'pivot_role' => 'admin', 'roles' => ['super_admin']], $this->describe($existing));
    }

    /**
     * @return array{team: string|null, pivot_role: string|null, roles: list<string>}
     */
    private function describe(User $user): array
    {
        $user->refresh();

        return [
            'team' => $user->currentTeam?->name,
            'pivot_role' => DB::table('team_user')
                ->where('user_id', $user->id)
                ->where('team_id', $user->current_team_id)
                ->value('role'),
            'roles' => DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_id', $user->id)
                ->pluck('roles.name')
                ->all(),
        ];
    }

    /**
     * Swap in a command whose stdin is an in-memory stream, so tests can
     * say whether "the terminal" exists and what was piped to it.
     */
    private function fakeStdin(string $contents, bool $terminal): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        $command = new class extends CreateAdminUser
        {
            /** @var resource|null */
            public $fakeStdin;

            public bool $fakeTerminal = false;

            protected function hasTerminal(): bool
            {
                return $this->fakeTerminal;
            }

            protected function stdin()
            {
                return $this->fakeStdin;
            }
        };
        $command->fakeStdin = $stream;
        $command->fakeTerminal = $terminal;

        $this->app->make(Kernel::class)->registerCommand($command);
    }
}
