<?php

namespace App\Domains\Files\Policies;

use App\Domains\Files\Models\FileOperation;
use App\Domains\Identity\Models\User;

class FileOperationPolicy
{
    public function view(User $user, FileOperation $fileOperation): bool
    {
        return $fileOperation->user_id === $user->id;
    }
}
