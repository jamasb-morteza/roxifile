<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Data\UpdateUserPasswordData;
use App\Domains\Identity\Models\User;

class UpdateUserPassword
{
    /**
     * Update the user's password.
     */
    public function handle(User $user, UpdateUserPasswordData $data): void
    {
        $user->update([
            'password' => $data->password,
        ]);
    }
}
