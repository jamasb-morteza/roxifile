<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Data\CreateDirectoryData;
use App\Domains\Files\Models\Directory;
use App\Domains\Identity\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

class CreateDirectory
{
    public function handle(User $user, CreateDirectoryData $data): Directory
    {
        $name = trim($data->name);

        if ($name === '' || str_contains($name, '/') || $name === '.' || $name === '..') {
            throw new \InvalidArgumentException('Directory names cannot be empty or contain slashes.');
        }

        $parent = $data->parentId !== null
            ? Directory::query()->where('user_id', $user->id)->findOrFail($data->parentId)
            : null;

        $path = $parent === null
            ? $name
            : $parent->path.'/'.$name;

        try {
            return Directory::create([
                'user_id' => $user->id,
                'parent_id' => $parent?->id,
                'name' => $name,
                'path' => $path,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new \InvalidArgumentException('A directory with that name already exists here.');
        }
    }
}
