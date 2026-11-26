<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Platform\CreateSuperAdmin;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * Create a platform super-admin: the first login on a new install.
 *
 * It runs from `kubectl exec`, CI runners and piped ssh sessions as often
 * as from a terminal, so it never prompts unless stdin really is a
 * terminal. Without one it fails at once with what to pass instead,
 * rather than printing a bare "Aborted." (no stdin) or blocking forever
 * on a prompt nobody can answer (stdin open, not a TTY).
 */
class CreateAdminUser extends Command
{
    protected $signature = 'orbital:make-admin
                            {--name= : Admin user name}
                            {--email= : Admin user email}
                            {--password= : Admin user password (visible in shell history, process lists and audit logs; prefer --password-stdin)}
                            {--password-stdin : Read the password from the first line of stdin}
                            {--generate-password : Generate a random password and print it once}';

    protected $description = 'Create a platform operator super-admin user';

    public const NO_TERMINAL_MESSAGE = 'orbital:make-admin needs --name, --email and a password when there is no terminal.';

    public const NO_TERMINAL_HINT = 'Pass --password-stdin and pipe the password, or re-run with `kubectl exec -it`.';

    public function handle(CreateSuperAdmin $createSuperAdmin): int
    {
        $passwordSources = array_filter([
            '--password' => $this->option('password') !== null,
            '--password-stdin' => (bool) $this->option('password-stdin'),
            '--generate-password' => (bool) $this->option('generate-password'),
        ]);

        if (count($passwordSources) > 1) {
            $this->error('Use only one of '.implode(', ', array_keys($passwordSources)).'.');

            return self::INVALID;
        }

        $interactive = $this->input->isInteractive() && $this->hasTerminal();

        $name = $this->option('name') ?? ($interactive ? $this->ask('Name') : null);
        $email = $this->option('email') ?? ($interactive ? $this->ask('Email') : null);

        $generated = false;

        if ($this->option('password') !== null) {
            $this->warn('--password is visible in shell history, process lists and Kubernetes audit logs. Prefer --password-stdin.');
            $password = $this->option('password');
        } elseif ($this->option('generate-password')) {
            $password = Str::password(24, symbols: false);
            $generated = true;
        } elseif ($this->option('password-stdin') && ! $this->hasTerminal()) {
            $password = $this->readPasswordFromStdin();
        } else {
            // No flag, or --password-stdin on a TTY: prompt, so `-it` plus
            // the flag doesn't sit waiting for input nobody knows to type.
            $password = $interactive ? $this->secret('Password') : null;
        }

        if (blank($name) || blank($email) || blank($password)) {
            if (! $interactive) {
                $this->error(self::NO_TERMINAL_MESSAGE);
                $this->line(self::NO_TERMINAL_HINT);
            } else {
                $this->error('Name, email, and password are all required.');
            }

            return self::INVALID;
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255'],
                // Same rule the password-change form uses (PasswordValidationRules).
                'password' => ['required', 'string', Password::default()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::INVALID;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("A user with email {$email} already exists.");

            return self::FAILURE;
        }

        // Checked first, and the action is one transaction: without the
        // role a half-created user would be left behind and a retry would
        // only report that the email is taken.
        if (! Role::where(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => null])->exists()) {
            $this->error('The super_admin role does not exist: this database has not been seeded.');
            $this->line('Run `php artisan db:seed --force` (or `php artisan orbital:seed-if-empty`), then try again.');

            return self::FAILURE;
        }

        $createSuperAdmin->create($name, $email, $password);

        $this->info("Super-admin created: {$name} <{$email}>");

        if ($generated) {
            $this->line('Generated password (shown once, store it now):');
            $this->line($password);
        }

        $this->info('Login at: '.config('app.url').'/admin');

        return self::SUCCESS;
    }

    /**
     * Whether stdin is a terminal. Unknown counts as no: failing loudly
     * beats hanging on a prompt.
     */
    protected function hasTerminal(): bool
    {
        $stdin = $this->stdin();

        if (! is_resource($stdin)) {
            return false;
        }

        if (function_exists('stream_isatty')) {
            return stream_isatty($stdin);
        }

        if (function_exists('posix_isatty')) {
            return posix_isatty($stdin);
        }

        return false;
    }

    /**
     * @return resource|null
     */
    protected function stdin()
    {
        return defined('STDIN') ? STDIN : null;
    }

    private function readPasswordFromStdin(): ?string
    {
        $stdin = $this->stdin();

        if (! is_resource($stdin)) {
            return null;
        }

        $line = fgets($stdin);

        return $line === false ? null : rtrim($line, "\r\n");
    }
}
