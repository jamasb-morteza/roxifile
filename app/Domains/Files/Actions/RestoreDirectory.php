<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class RestoreDirectory
{
    public function handle(Directory $directory): Directory
    {
        if ($directory->purging) {
            throw new \InvalidArgumentException('A folder being permanently deleted cannot be restored.');
        }

        if ($directory->parent_id !== null && ! Directory::query()->whereKey($directory->parent_id)->exists()) {
            throw new \InvalidArgumentException('Restore the parent folder first.');
        }

        try {
            DB::transaction(function () use ($directory): void {
                $batch = $directory->deletion_batch;
                $directory->restore();

                if ($batch !== null) {
                    Directory::onlyTrashed()->where('user_id', $directory->user_id)
                        ->where('deletion_batch', $batch)->update(['deleted_at' => null, 'deletion_batch' => null]);
                    File::onlyTrashed()->where('user_id', $directory->user_id)
                        ->where('deletion_batch', $batch)->update(['deleted_at' => null, 'deletion_batch' => null]);
                }

                $directory->forceFill(['deletion_batch' => null])->save();
            });
        } catch (UniqueConstraintViolationException) {
            throw new \InvalidArgumentException('A folder with that name already exists. Rename it before restoring this folder.');
        }

        return $directory->refresh();
    }
}
