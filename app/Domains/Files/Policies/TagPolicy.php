<?php

namespace App\Domains\Files\Policies;

use App\Domains\Files\Models\Tag;
use App\Domains\Identity\Models\User;

class TagPolicy
{
    public function view(User $user, Tag $tag): bool
    {
        return $tag->user_id === $user->id;
    }

    public function update(User $user, Tag $tag): bool
    {
        return $tag->user_id === $user->id;
    }

    public function delete(User $user, Tag $tag): bool
    {
        return $tag->user_id === $user->id;
    }
}
