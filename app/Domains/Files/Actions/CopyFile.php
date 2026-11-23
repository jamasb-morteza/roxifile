<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Data\CopyFileData;
use App\Domains\Files\Enums\FileOperationType;
use App\Domains\Files\Enums\FileStatus;
use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Jobs\TelegramOperationJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CopyFile
{
    public function __construct(private readonly EnsureStorageCapacity $capacity) {}

    public function handle(File $source, CopyFileData $data): FileOperation
    {
        $operation = DB::transaction(function () use ($source, $data): FileOperation {
            User::query()->whereKey($source->user_id)->lockForUpdate()->firstOrFail();
            $source = File::query()->lockForUpdate()->findOrFail($source->id);
            if ($source->encryption_header !== null || $source->connection?->encryption_mode !== 'none') {
                throw ValidationException::withMessages(['file' => 'Encrypted copies require device-side encryption and are not available yet.']);
            }
            if ($data->requestId !== null) {
                $existing = FileOperation::query()->where('user_id', $source->user_id)->where('operation', FileOperationType::Copy)
                    ->where('metadata->request_id', $data->requestId)->first();
                if ($existing !== null) {
                    return $existing;
                }
            }
            $this->capacity->handle($source->user, $source->size, $source->size);
            if ($source->status !== FileStatus::Uploaded || $source->connection->status !== TelegramConnectionStatus::Connected) {
                throw new \InvalidArgumentException('Copy requires a stored source and an active connection.');
            }
            if ($data->directoryId !== null) {
                Directory::query()->where('user_id', $source->user_id)->findOrFail($data->directoryId);
            }
            $name = trim($data->name ?? pathinfo($source->name, PATHINFO_FILENAME).' (copy)'.($source->extension ? '.'.$source->extension : ''));
            if ($name === '' || File::query()->where('user_id', $source->user_id)->where('directory_id', $data->directoryId)->where('name', $name)->exists()) {
                throw new \InvalidArgumentException('Choose a non-empty name that is not already used in this folder.');
            }
            $copy = $source->user->files()->create(array_merge($source->only([
                'telegram_account_id', 'telegram_connection_id', 'telegram_chat_id', 'mime_type', 'file_type', 'size', 'checksum', 'description',
            ]), [
                'directory_id' => $data->directoryId, 'name' => $name, 'original_name' => $name,
                'extension' => mb_substr(pathinfo($name, PATHINFO_EXTENSION), 0, 16) ?: null, 'status' => FileStatus::Pending,
            ]));
            $copy->tags()->sync($source->tags()->pluck('tags.id')->all());

            return $source->user->fileOperations()->create([
                'file_id' => $copy->id, 'telegram_account_id' => $source->telegram_account_id,
                'telegram_connection_id' => $source->telegram_connection_id, 'operation' => FileOperationType::Copy,
                'status' => 'pending', 'reserved_temp_bytes' => $source->size,
                'metadata' => ['file_name' => $copy->name, 'source_file_id' => $source->id, 'request_id' => $data->requestId,
                    'temp_path' => 'telegram-upload/'.Str::ulid(), 'random_id' => random_int(1, PHP_INT_MAX)],
            ]);
        });
        if ($operation->wasRecentlyCreated) {
            TelegramOperationJob::dispatch($operation->id);
        }

        return $operation->refresh();
    }
}
