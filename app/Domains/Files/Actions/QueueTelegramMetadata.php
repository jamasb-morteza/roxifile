<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Data\QueueTelegramMetadataData;
use App\Domains\Files\Enums\FileOperationStatus;
use App\Domains\Files\Enums\FileOperationType;
use App\Domains\Files\Enums\FileStatus;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Telegram\Jobs\TelegramOperationJob;
use Illuminate\Support\Facades\DB;

/** Each operation ID is a monotonic revision; workers project the latest committed source. */
class QueueTelegramMetadata
{
    public function handle(QueueTelegramMetadataData $data): void
    {
        if ($data->status !== FileStatus::Uploaded || $data->telegramMessageId === null) {
            return;
        }
        DB::transaction(function () use ($data): void {
            $current = File::query()->with('connection')->lockForUpdate()->find($data->fileId);
            $mode = $current?->connection?->encryption_mode;
            if ($current === null || ! in_array($mode, ['none', 'contents', 'contents_and_metadata'], true)
                || ($mode === 'none' && $current->encryption_header !== null)
                || ($mode !== 'none' && $current->encryption_header === null)
                || $current->status !== FileStatus::Uploaded
                || $current->telegram_message_id === null) {
                return;
            }
            $operation = FileOperation::query()->forceCreate([
                'user_id' => $current->user_id, 'file_id' => $current->id,
                'telegram_connection_id' => $current->telegram_connection_id,
                'operation' => FileOperationType::Metadata, 'status' => FileOperationStatus::Pending,
                'progress' => 0, 'attempts' => 0, 'reserved_temp_bytes' => 0,
                'metadata' => $mode === 'contents_and_metadata' ? [] : ['file_name' => $current->name],
            ]);
            TelegramOperationJob::dispatch($operation->id)->afterCommit();
        });
    }
}
