<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Data\UpdateProfileData;
use App\Domains\Identity\Models\User;

class UpdateUserProfile
{
    /**
     * Update the user's profile, resetting email verification if the email changed.
     */
    public function handle(User $user, UpdateProfileData $data): void
    {
        $user->fill([
            'name' => $data->name,
            'email' => $data->email,
        ]);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();
    }
}
