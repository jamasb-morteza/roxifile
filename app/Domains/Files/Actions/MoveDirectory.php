<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Models\Directory;
use App\Domains\Files\Services\DirectoryPathService;
use App\Domains\Identity\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class MoveDirectory
{
    public function __construct(
        private readonly DirectoryPathService $paths,
    ) {}

    public function handle(Directory $directory, ?int $newParentId): Directory
    {
        return DB::transaction(function () use ($directory, $newParentId): Directory {
            User::query()->whereKey($directory->user_id)->lockForUpdate()->firstOrFail();
            $directory = Directory::query()->lockForUpdate()->findOrFail($directory->id);

            if ($newParentId !== null) {
                if ((int) $newParentId === (int) $directory->id) {
                    throw new \InvalidArgumentException('A directory cannot be moved into itself.');
                }

                $newParent = Directory::query()->where('user_id', $directory->user_id)->findOrFail($newParentId);

                // Prevent moving a directory into its own subtree.
                $ancestor = $newParent;

                while ($ancestor !== null) {
                    if ((int) $ancestor->id === (int) $directory->id) {
                        throw new \InvalidArgumentException('A directory cannot be moved into its own subtree.');
                    }

                    $ancestor = $ancestor->parent_id !== null
                        ? Directory::find($ancestor->parent_id)
                        : null;
                }
            } else {
                $newParent = null;
            }

            try {
                $this->paths->move($directory, $newParent);
            } catch (UniqueConstraintViolationException) {
                throw new \InvalidArgumentException('A directory with that name already exists in the target location.');
            }

            return $directory->refresh();
        });
    }
}
