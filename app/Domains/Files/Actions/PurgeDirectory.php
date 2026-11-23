<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;

class PurgeDirectory
{
    public function __construct(
        private readonly DeleteDirectory $deleteDirectory,
        private readonly PurgeFile $purgeFile,
    ) {}

    public function handle(Directory $directory): bool
    {
        if (! $directory->trashed()) {
            $this->deleteDirectory->handle($directory);
        }

        $ids = $this->deleteDirectory->subtreeIds($directory, includeTrashed: true);
        Directory::withTrashed()->whereIn('id', $ids)->update(['purging' => true]);

        File::withTrashed()->where('user_id', $directory->user_id)->whereIn('directory_id', $ids)
            ->eachById(function (File $file): void {
                $this->purgeFile->handle($file);
            });

        $this->finishPending($directory->user_id);

        return ! Directory::withTrashed()->whereKey($directory->id)->exists();
    }

    public function finishPending(int $userId): void
    {
        Directory::withTrashed()->where('user_id', $userId)->where('purging', true)
            ->eachById(function (Directory $directory) use ($userId): void {
                $ids = $this->deleteDirectory->subtreeIds($directory, includeTrashed: true);

                if (! File::withTrashed()->where('user_id', $userId)->whereIn('directory_id', $ids)->exists()) {
                    Directory::withTrashed()->where('user_id', $userId)->whereIn('id', $ids)->forceDelete();
                }
            });
    }
}
