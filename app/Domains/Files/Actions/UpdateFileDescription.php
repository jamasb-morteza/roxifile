<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Data\QueueTelegramMetadataData;
use App\Domains\Files\Data\UpdateFileDescriptionData;
use App\Domains\Files\Models\File;
use Illuminate\Support\Facades\DB;

class UpdateFileDescription
{
    public function __construct(private readonly QueueTelegramMetadata $projection) {}

    public function handle(File $file, UpdateFileDescriptionData $data): void
    {
        $file->rejectPlaintextMetadata();
        DB::transaction(function () use ($file, $data): void {
            $file->update(['description' => $data->description]);
            $this->projection->handle(new QueueTelegramMetadataData($file->id, $file->status, $file->telegram_message_id));
        });
    }
}
