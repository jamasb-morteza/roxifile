<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Data\QueueTelegramMetadataData;
use App\Domains\Files\Models\File;
use Illuminate\Support\Facades\DB;

class RenameFile
{
    public function handle(File $file, string $newName): File
    {
        $file->rejectPlaintextMetadata();

        $newName = trim($newName);

        if ($newName === '' || str_contains($newName, '/') || $newName === '.' || $newName === '..') {
            throw new \InvalidArgumentException('File names cannot be empty or contain slashes.');
        }

        DB::transaction(function () use ($file, $newName): void {
            $file->update(['name' => $newName]);
            app(QueueTelegramMetadata::class)->handle(new QueueTelegramMetadataData($file->id, $file->status, $file->telegram_message_id));
        });

        return $file->refresh();
    }
}
