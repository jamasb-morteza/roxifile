<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Enums\FileStatus;
use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;

/**
 * Restore a soft-deleted file from Trash.
 */
class RestoreFile
{
    public function handle(File $file): File
    {
        if ($file->status === FileStatus::Deleting) {
            throw new \InvalidArgumentException('A file being permanently deleted cannot be restored.');
        }

        if ($file->directory_id !== null && ! Directory::query()->whereKey($file->directory_id)->exists()) {
            throw new \InvalidArgumentException('Restore the parent folder before restoring this file.');
        }

        $file->restore();

        return $file;
    }
}
