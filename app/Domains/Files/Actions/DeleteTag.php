<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Data\QueueTelegramMetadataData;
use App\Domains\Files\Models\Tag;
use Illuminate\Support\Facades\DB;

class DeleteTag
{
    public function handle(Tag $tag): void
    {
        DB::transaction(function () use ($tag): void {
            $files = $tag->files()->get();
            $tag->files()->detach();
            $tag->delete();
            foreach ($files as $file) {
                app(QueueTelegramMetadata::class)->handle(new QueueTelegramMetadataData($file->id, $file->status, $file->telegram_message_id));
            }
        });
    }
}
