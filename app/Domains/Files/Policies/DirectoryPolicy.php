<?php

namespace App\Domains\Files\Policies;

use App\Domains\Files\Models\Directory;
use App\Domains\Identity\Models\User;

class DirectoryPolicy
{
    public function view(User $user, Directory $directory): bool
    {
        return $directory->user_id === $user->id;
    }

    public function update(User $user, Directory $directory): bool
    {
        return $directory->user_id === $user->id;
    }

    public function delete(User $user, Directory $directory): bool
    {
        return $directory->user_id === $user->id;
    }
}
