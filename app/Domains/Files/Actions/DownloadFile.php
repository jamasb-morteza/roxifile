<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Enums\FileOperationStatus;
use App\Domains\Files\Enums\FileOperationType;
use App\Domains\Files\Enums\FileStatus;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Jobs\TelegramOperationJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DownloadFile
{
    public function __construct(private readonly EnsureStorageCapacity $capacity) {}

    public function handle(File $file): FileOperation
    {
        $operation = DB::transaction(function () use ($file): FileOperation {
            User::query()->whereKey($file->user_id)->lockForUpdate()->firstOrFail();
            $file = File::query()->lockForUpdate()->findOrFail($file->id);
            if ($file->status !== FileStatus::Uploaded) {
                throw new \InvalidArgumentException('Only a stored file can be downloaded.');
            }

            $existing = FileOperation::query()->where('file_id', $file->id)
                ->where('operation', FileOperationType::Download)->latest('id')->first();
            if ($existing !== null && in_array($existing->status, [FileOperationStatus::Pending, FileOperationStatus::Downloading, FileOperationStatus::Retrying], true)) {
                return $existing;
            }
            if ($existing !== null && $this->isReady($existing)) {
                return $existing;
            }

            $this->capacity->handle($file->user, temporaryBytes: $file->size);

            return $file->user->fileOperations()->create([
                'file_id' => $file->id,
                'telegram_account_id' => $file->telegram_account_id,
                'telegram_connection_id' => $file->telegram_connection_id,
                'operation' => FileOperationType::Download,
                'status' => FileOperationStatus::Pending,
                'reserved_temp_bytes' => $file->size,
                'metadata' => ['file_name' => $file->name],
            ]);
        });

        if ($operation->wasRecentlyCreated) {
            TelegramOperationJob::dispatch($operation->id);
        }

        return $operation->refresh();
    }

    public function prepared(File $file, ?int $operationId): ?FileOperation
    {
        return DB::transaction(function () use ($file, $operationId): ?FileOperation {
            $operation = FileOperation::query()->where('user_id', $file->user_id)->where('file_id', $file->id)
                ->where('operation', FileOperationType::Download)
                ->when($operationId !== null, fn ($query) => $query->whereKey($operationId))
                ->latest('id')->lockForUpdate()->first();
            if ($operation !== null && $this->isReady($operation)) {
                $operation->forceFill(['metadata' => array_merge($operation->metadata ?? [], [
                    'expires_at' => now()->addMinutes(15)->toIso8601String(),
                ])])->save();
            }

            return $operation;
        });
    }

    public function isReady(FileOperation $operation): bool
    {
        $path = $operation->metadata['downloaded_path'] ?? null;
        $expires = $operation->metadata['expires_at'] ?? null;

        return $operation->status === FileOperationStatus::Completed && is_string($path)
            && is_string($expires) && now()->lt($expires) && Storage::disk('local')->exists($path);
    }
}
