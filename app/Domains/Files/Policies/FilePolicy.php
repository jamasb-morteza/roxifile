<?php

namespace App\Domains\Files\Policies;

use App\Domains\Files\Models\File;
use App\Domains\Identity\Models\User;

class FilePolicy
{
    public function view(User $user, File $file): bool
    {
        return $file->user_id === $user->id;
    }

    public function update(User $user, File $file): bool
    {
        return $file->user_id === $user->id;
    }

    public function delete(User $user, File $file): bool
    {
        return $file->user_id === $user->id;
    }
}
