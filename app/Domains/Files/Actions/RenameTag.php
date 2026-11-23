<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Data\QueueTelegramMetadataData;
use App\Domains\Files\Models\Tag;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\DB;

class RenameTag
{
    public function handle(Tag $tag, string $newName): Tag
    {
        $newName = trim($newName);

        if ($newName === '' || mb_strlen($newName) > 32) {
            throw new \InvalidArgumentException('Tag names must be 1-32 characters.');
        }

        return DB::transaction(function () use ($tag, $newName): Tag {
            User::query()->whereKey($tag->user_id)->lockForUpdate()->firstOrFail();
            $tag = Tag::query()->lockForUpdate()->findOrFail($tag->id);
            $files = $tag->files()->get();
            $existing = Tag::withTrashed()
                ->where('user_id', $tag->user_id)
                ->where('name', $newName)
                ->whereKeyNot($tag->id)
                ->first();

            if ($existing !== null) {
                // Merge into the existing tag, then drop this one.
                $fileIds = $tag->files()->pluck('files.id');

                if ($existing->trashed()) {
                    $existing->restore();
                }
                $existing->files()->syncWithoutDetaching($fileIds);
                $tag->files()->detach();
                $tag->delete();

                foreach ($files as $file) {
                    app(QueueTelegramMetadata::class)->handle(new QueueTelegramMetadataData($file->id, $file->status, $file->telegram_message_id));
                }

                return $existing->refresh();
            }

            $tag->update(['name' => $newName]);
            foreach ($files as $file) {
                app(QueueTelegramMetadata::class)->handle(new QueueTelegramMetadataData($file->id, $file->status, $file->telegram_message_id));
            }

            return $tag->refresh();
        });
    }
}
