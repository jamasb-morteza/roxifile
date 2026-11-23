<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Data\GoogleIdentityData;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuthenticateWithGoogle
{
    public function handle(GoogleIdentityData $identity): ?User
    {
        if (! $identity->emailIsVerified || blank($identity->id) || blank($identity->email)) {
            return null;
        }

        return DB::transaction(function () use ($identity): ?User {
            $user = User::query()
                ->where('google_id', $identity->id)
                ->first();

            if ($user === null) {
                $user = User::query()
                    ->where('email', $identity->email)
                    ->first() ?? new User;
            }

            if ($user->exists && filled($user->google_id) && $user->google_id !== $identity->id) {
                return null;
            }

            if (! $user->exists) {
                $user->name = filled($identity->name)
                    ? $identity->name
                    : Str::before($identity->email, '@');
                $user->email = $identity->email;
                $user->password = Str::random(64);
            }

            $user->google_id = $identity->id;
            $user->email_verified_at ??= now();
            $user->save();

            return $user;
        });
    }
}
