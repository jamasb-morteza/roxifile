<?php

namespace App\Console\Commands;

use App\Domains\Identity\Actions\MakeSuperAdmin;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Bootstrap the first (or any additional) super admin: promotes an existing
 * user by email, or creates the account when it does not exist. There is no
 * UI path to become super admin, so this is the only entry point.
 */
#[Signature('user:make-super-admin {email : The email address of the user to promote or create} {--name= : Display name used when the user does not exist yet} {--password= : Password used when the user does not exist yet (a random one is generated and printed when omitted)}')]
#[Description('Create a super admin user or promote an existing user to super admin')]
class MakeSuperAdminCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MakeSuperAdmin $makeSuperAdmin): int
    {
        $email = trim((string) $this->argument('email'));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('"'.$email.'" is not a valid email address.');

            return self::FAILURE;
        }

        ['user' => $user, 'created' => $created, 'generated_password' => $generatedPassword] = $makeSuperAdmin->handle(
            $email,
            $this->option('name'),
            $this->option('password'),
        );

        if (! $created) {
            $this->info($user->email.' is now a super admin.');

            return self::SUCCESS;
        }

        $this->info('Super admin created: '.$user->name.' <'.$user->email.'>');

        if (is_string($generatedPassword)) {
            $this->warn('Generated password (shown once): '.$generatedPassword);
        }

        return self::SUCCESS;
    }
}
