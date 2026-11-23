<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Models\Tag;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\DB;

class CreateTag
{
    public function handle(User $user, string $name): Tag
    {
        $name = trim($name);

        if ($name === '') {
            throw new \InvalidArgumentException('Tag names cannot be empty.');
        }

        if (mb_strlen($name) > 32) {
            throw new \InvalidArgumentException('Tag names are limited to 32 characters.');
        }

        return DB::transaction(function () use ($user, $name): Tag {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $tag = $user->tags()->withTrashed()->firstOrCreate(['name' => $name]);

            if ($tag->trashed()) {
                $tag->restore();
            }

            return $tag;
        });
    }
}
