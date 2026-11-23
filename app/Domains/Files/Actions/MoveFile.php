<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Data\QueueTelegramMetadataData;
use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\DB;

class MoveFile
{
    public function handle(File $file, ?int $newDirectoryId): File
    {
        $directory = $newDirectoryId === null
            ? null
            : Directory::query()->where('user_id', $file->user_id)->findOrFail($newDirectoryId);

        DB::transaction(function () use ($file, $directory): void {
            $file->update(['directory_id' => $directory?->id]);
            app(QueueTelegramMetadata::class)->handle(new QueueTelegramMetadataData($file->id, $file->status, $file->telegram_message_id));
        });

        return $file->refresh();
    }

    /**
     * @param  list<int>  $fileIds
     */
    public function bulk(User $user, array $fileIds, ?int $newDirectoryId): int
    {
        $moved = 0;

        foreach ($fileIds as $id) {
            $file = File::withTrashed()->where('user_id', $user->id)->find($id);

            if ($file === null) {
                continue;
            }

            $this->handle($file, $newDirectoryId);
            $moved++;
        }

        return $moved;
    }
}
