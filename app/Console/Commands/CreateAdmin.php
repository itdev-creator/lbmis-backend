<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Creates the first admin account (or gives an existing user the admin role).
 *
 *   php artisan lbmis:create-admin it@paynwash.my --name="IT Admin"
 *
 * Asks for the password when run interactively; otherwise generates one and prints it once.
 */
class CreateAdmin extends Command
{
    protected $signature = 'lbmis:create-admin {email : Email address used to sign in} {--name=Administrator : Display name}';

    protected $description = 'Create an LBMIS admin user';

    public function handle(): int
    {
        $email = Str::lower((string) $this->argument('email'));

        if (Validator::make(['email' => $email], ['email' => 'required|email'])->fails()) {
            $this->error('That is not a valid email address.');

            return self::FAILURE;
        }

        $this->call('lbmis:sync-permissions');

        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            $user->syncRoles(['admin']);
            $user->forceFill(['is_active' => true])->save();
            $this->info("{$email} already exists and now has the admin role.");

            return self::SUCCESS;
        }

        $generated = false;
        if ($this->input->isInteractive()) {
            $password = (string) $this->secret('Password (at least 12 characters)');
            if (Str::length($password) < 12 || $password !== (string) $this->secret('Repeat the password')) {
                $this->error('The passwords do not match or are shorter than 12 characters.');

                return self::FAILURE;
            }
        } else {
            $password = Str::password(16);
            $generated = true;
        }

        $user = new User;
        $user->forceFill([
            'name' => (string) $this->option('name'),
            'email' => $email,
            'password' => $password,
            'email_verified_at' => now(),
            'is_active' => true,
        ])->save();
        $user->assignRole('admin');

        $this->info("Admin {$email} created.");
        if ($generated) {
            $this->warn("Generated password (shown once, change it after signing in): {$password}");
        }

        return self::SUCCESS;
    }
}
