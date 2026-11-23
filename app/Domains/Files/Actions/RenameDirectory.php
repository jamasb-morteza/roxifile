<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Models\Directory;
use App\Domains\Files\Services\DirectoryPathService;
use App\Domains\Identity\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class RenameDirectory
{
    public function __construct(
        private readonly DirectoryPathService $paths,
    ) {}

    public function handle(Directory $directory, string $newName): Directory
    {
        return DB::transaction(function () use ($directory, $newName): Directory {
            User::query()->whereKey($directory->user_id)->lockForUpdate()->firstOrFail();
            $directory = Directory::query()->lockForUpdate()->findOrFail($directory->id);

            $newName = trim($newName);

            if ($newName === '' || str_contains($newName, '/') || $newName === '.' || $newName === '..') {
                throw new \InvalidArgumentException('Directory names cannot be empty or contain slashes.');
            }

            try {
                $this->paths->rename($directory, $newName);
            } catch (UniqueConstraintViolationException) {
                throw new \InvalidArgumentException('A directory with that name already exists here.');
            }

            return $directory->refresh();
        });
    }
}
