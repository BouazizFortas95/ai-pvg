<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;

class GrantAdminAccess extends Command
{
    protected $signature = 'user:grant-admin {email : Email address of the user who should become an administrator}';

    protected $description = 'Grant administrator access to an existing user (Filament panel access)';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("The value [{$email}] is not a valid email address.");

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error("No user exists with the email [{$email}]. Create the user first.");

            return self::FAILURE;
        }

        if ($user->role === UserRole::Admin) {
            $this->warn("The user [{$email}] is already an administrator.");

            return self::SUCCESS;
        }

        $user->forceFill(['role' => UserRole::Admin->value])->save();

        $this->info("Administrator access granted to [{$email}].");

        return self::SUCCESS;
    }
}
