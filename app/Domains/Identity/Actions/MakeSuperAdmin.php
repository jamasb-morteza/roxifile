<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Models\User;
use Illuminate\Support\Str;

/**
 * Grant the super-admin flag to the user with the given email, creating the
 * account when it does not exist yet (bootstrap path — there is no UI to
 * become the first super admin).
 *
 * @return array{user: User, created: bool, generated_password: string|null}
 *                                                                           plaintext password only when one was randomly generated here
 */
class MakeSuperAdmin
{
    /**
     * @return array{user: User, created: bool, generated_password: string|null}
     *                                                                           plaintext password only when one was randomly generated here
     */
    public function handle(string $email, ?string $name = null, ?string $password = null): array
    {
        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            $user->is_super_admin = true;
            $user->save();

            return ['user' => $user, 'created' => false, 'generated_password' => null];
        }

        $generatedPassword = $password === null ? Str::password(16) : null;

        $user = User::create([
            'name' => $name ?? Str::before($email, '@'),
            'email' => $email,
            'password' => $password ?? $generatedPassword,
            'is_super_admin' => true,
        ]);

        return ['user' => $user, 'created' => true, 'generated_password' => $generatedPassword];
    }
}
