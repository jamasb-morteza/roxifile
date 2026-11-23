<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Data\QueueTelegramMetadataData;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\Tag;
use Illuminate\Support\Facades\DB;

class DetachTag
{
    public function handle(File $file, Tag $tag): File
    {
        $file->rejectPlaintextMetadata();

        DB::transaction(function () use ($file, $tag): void {
            $file->tags()->detach([$tag->id]);
            app(QueueTelegramMetadata::class)->handle(new QueueTelegramMetadataData($file->id, $file->status, $file->telegram_message_id));
        });

        return $file->refresh();
    }
}
