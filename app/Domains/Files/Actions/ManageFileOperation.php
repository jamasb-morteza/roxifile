<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Enums\FileOperationStatus;
use App\Domains\Files\Enums\FileOperationType;
use App\Domains\Files\Enums\FileStatus;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Jobs\TelegramOperationJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ManageFileOperation
{
    public function __construct(private readonly EnsureStorageCapacity $capacity) {}

    public function retry(FileOperation $operation): FileOperation
    {
        DB::transaction(function () use ($operation): void {
            User::query()->whereKey($operation->user_id)->lockForUpdate()->firstOrFail();
            $operation = FileOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if (! in_array($operation->status, [FileOperationStatus::Failed, FileOperationStatus::Cancelled], true)) {
                throw new \InvalidArgumentException('Only a failed or cancelled operation with a known outcome can be retried.');
            }
            $file = $operation->file()->withTrashed()->first();
            if ($file === null || ($file->trashed() && $operation->operation !== FileOperationType::Delete)) {
                throw new \InvalidArgumentException('Restore the source file before retrying this operation.');
            }
            $metadata = $operation->metadata ?? [];
            if ($operation->operation === FileOperationType::Upload && empty($metadata['upload_result'])
                && (! is_string($metadata['temp_path'] ?? null) || ! Storage::disk('local')->exists($metadata['temp_path']))) {
                throw new \InvalidArgumentException('The original upload expired. Upload the file again.');
            }
            $bytes = in_array($operation->operation, [FileOperationType::Upload, FileOperationType::Download, FileOperationType::Copy], true) ? $file->size : 0;
            $additionalBytes = in_array($operation->operation, [FileOperationType::Upload, FileOperationType::Copy], true) && $file->status === FileStatus::Failed ? $file->size : 0;
            $this->capacity->handle($operation->user, $additionalBytes, max(0, $bytes - $operation->reserved_temp_bytes));
            if ($operation->operation === FileOperationType::Copy && empty($metadata['temp_path'])) {
                $metadata['temp_path'] = 'telegram-upload/'.Str::ulid();
            }
            unset($metadata['cancel_requested_at']);
            $operation->forceFill([
                'status' => FileOperationStatus::Pending, 'error' => null, 'completed_at' => null,
                'available_at' => null, 'lease_token' => null, 'lease_expires_at' => null,
                'attempts' => 0, 'progress' => 0, 'metadata' => $metadata, 'reserved_temp_bytes' => $bytes,
            ])->save();
            if (in_array($operation->operation, [FileOperationType::Upload, FileOperationType::Copy], true)) {
                $file->update(['status' => FileStatus::Pending]);
            }
        });
        TelegramOperationJob::dispatch($operation->id);

        return $operation->refresh();
    }

    public function cancel(FileOperation $operation): FileOperation
    {
        DB::transaction(function () use ($operation): void {
            $operation = FileOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if (in_array($operation->status, [FileOperationStatus::Completed, FileOperationStatus::NeedsReview], true)) {
                throw new \InvalidArgumentException('This operation has finished or needs review and cannot be cancelled.');
            }
            if (! $operation->lease_expires_at?->isFuture()
                && ! empty($operation->metadata['send_started_at']) && empty($operation->metadata['upload_result'])) {
                throw new \InvalidArgumentException('The remote transfer outcome needs review before it can be cancelled.');
            }
            if ($operation->lease_expires_at?->isFuture()) {
                if ($operation->operation === FileOperationType::Delete) {
                    throw new \InvalidArgumentException('Permanent deletion has started and cannot be cancelled.');
                }
                $operation->forceFill(['metadata' => array_merge($operation->metadata ?? [], ['cancel_requested_at' => now()->toIso8601String()])])->save();

                return;
            }
            $this->finishCancellation($operation);
        });

        return $operation->refresh();
    }

    public function finishCancellation(FileOperation $operation): void
    {
        $metadata = $operation->metadata ?? [];
        foreach (['temp_path', 'downloaded_path'] as $key) {
            if (is_string($metadata[$key] ?? null)) {
                Storage::disk('local')->delete($metadata[$key]);
                $metadata[$key] = null;
            }
        }
        $file = $operation->file()->withTrashed()->first();
        $result = $metadata['upload_result'] ?? null;
        $knownUpload = $file !== null && in_array($operation->operation, [FileOperationType::Upload, FileOperationType::Copy], true)
            && is_array($result) && (int) ($result['message_id'] ?? 0) > 0;
        if ($knownUpload) {
            $file->forceFill([
                'status' => FileStatus::Uploaded, 'telegram_chat_id' => $file->telegram_chat_id ?? $file->connection?->chat_id,
                'telegram_message_id' => (int) $result['message_id'],
                'telegram_media_id' => $result['media_id'] ?? null, 'telegram_file_id' => $result['file_id'] ?? null,
            ])->save();
        }
        if (! $knownUpload && $file !== null && in_array($operation->operation, [FileOperationType::Upload, FileOperationType::Copy], true)) {
            $file->update(['status' => FileStatus::Failed]);
        }
        if ($file !== null && $operation->operation === FileOperationType::Delete) {
            $file->update(['status' => FileStatus::Uploaded]);
        }
        $operation->forceFill([
            'status' => $knownUpload ? FileOperationStatus::Completed : FileOperationStatus::Cancelled, 'completed_at' => now(),
            'progress' => $knownUpload ? 100 : $operation->progress,
            'reserved_temp_bytes' => 0, 'metadata' => $metadata, 'error' => $knownUpload ? null : 'Cancelled by user.',
        ])->save();
    }
}
