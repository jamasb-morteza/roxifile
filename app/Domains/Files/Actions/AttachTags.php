<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Data\QueueTelegramMetadataData;
use App\Domains\Files\Models\File;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\DB;

class AttachTags
{
    public function __construct(
        private readonly CreateTag $createTag,
    ) {}

    /**
     * Attach tags (creating missing ones) to a file the user owns.
     *
     * @param  list<string>  $names
     */
    public function handle(User $user, File $file, array $names): File
    {
        $file->rejectPlaintextMetadata();

        $tagIds = [];

        foreach ($names as $name) {
            $name = trim((string) $name);

            if ($name === '') {
                continue;
            }

            $tagIds[] = $this->createTag->handle($user, $name)->id;
        }

        if ($tagIds !== []) {
            DB::transaction(function () use ($file, $tagIds): void {
                $file->tags()->syncWithoutDetaching($tagIds);
                app(QueueTelegramMetadata::class)->handle(new QueueTelegramMetadataData($file->id, $file->status, $file->telegram_message_id));
            });
        }

        return $file->refresh();
    }

    /**
     * @param  list<int>  $fileIds
     * @param  list<string>  $names
     */
    public function bulkAttach(User $user, array $fileIds, array $names): int
    {
        File::query()->with('connection')->where('user_id', $user->id)->whereIn('id', $fileIds)->each(fn (File $file) => $file->rejectPlaintextMetadata());
        $tagIds = [];

        foreach ($names as $name) {
            $name = trim((string) $name);

            if ($name === '') {
                continue;
            }

            $tagIds[] = $this->createTag->handle($user, $name)->id;
        }

        if ($tagIds === []) {
            return 0;
        }

        $count = 0;

        File::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $fileIds)
            ->each(function (File $file) use ($tagIds, &$count): void {
                DB::transaction(function () use ($file, $tagIds): void {
                    $file->tags()->syncWithoutDetaching($tagIds);
                    app(QueueTelegramMetadata::class)->handle(new QueueTelegramMetadataData($file->id, $file->status, $file->telegram_message_id));
                });
                $count++;
            });

        return $count;
    }
}
