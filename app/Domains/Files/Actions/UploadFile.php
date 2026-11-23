<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Data\UploadFileData;
use App\Domains\Files\Enums\FileOperationStatus;
use App\Domains\Files\Enums\FileOperationType;
use App\Domains\Files\Enums\FileStatus;
use App\Domains\Files\Enums\FileType;
use App\Domains\Files\Events\FileUploadFailed;
use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Files\Services\EncryptedFileFormat;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Actions\SendTelegramUploadStream;
use App\Domains\Telegram\Data\SendTelegramUploadStreamData;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Create upload records, then stream PHP's temporary upload file to the worker. */
class UploadFile
{
    public function __construct(
        private readonly EnsureStorageCapacity $capacity,
        private readonly EncryptedFileFormat $encryptedFormat,
        private readonly SendTelegramUploadStream $sendTelegramUploadStream,
    ) {}

    public function handle(User $user, UploadFileData $data): FileOperation
    {
        $uploaded = $data->file;

        if (! $uploaded->isValid()) {
            throw new \InvalidArgumentException('The uploaded file is invalid.');
        }

        $directory = $data->directoryId !== null
            ? Directory::query()->where('user_id', $user->id)->findOrFail($data->directoryId)
            : null;

        // MIME from content, never extension alone (spec §18).
        $mimeType = $data->encrypted ? 'application/octet-stream' : ($uploaded->getMimeType() ?: 'application/octet-stream');
        $header = $data->encrypted ? $this->encryptedFormat->inspect($uploaded, $data->connection->id) : null;
        $sourcePath = $uploaded->getRealPath();
        $checksum = is_string($sourcePath) ? hash_file('sha256', $sourcePath) : false;
        if (! is_string($checksum)) {
            throw new \RuntimeException('The uploaded file could not be checked.');
        }

        $operation = DB::transaction(function () use ($user, $data, $uploaded, $mimeType, $checksum, $directory, $header): FileOperation {
            $this->capacity->handle($user, (int) $uploaded->getSize(), 0);
            if ($directory !== null && ! Directory::query()->where('user_id', $user->id)->whereKey($directory->id)->exists()) {
                throw new \InvalidArgumentException('The destination folder is no longer available.');
            }
            $connection = TelegramConnection::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($data->connection->id);
            if ($connection->status !== TelegramConnectionStatus::Connected) {
                throw new \InvalidArgumentException('This connection is not accepting new uploads.');
            }
            if (($connection->encryption_mode !== 'none') !== $data->encrypted || ! in_array($connection->encryption_mode, ['none', 'contents', 'contents_and_metadata'], true)) {
                throw ValidationException::withMessages(['file' => 'The upload encryption must match the connection. Unlock and encrypt protected files on your device.']);
            }
            $protected = $connection->encryption_mode === 'contents_and_metadata';
            if ($protected && ($data->protectedMetadata === null || $data->plaintextMime !== null || ! preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $uploaded->getClientOriginalName()))) {
                throw ValidationException::withMessages(['file' => 'Protected uploads require an opaque file identifier and encrypted metadata.']);
            }
            if ($connection->encryption_locked_at === null) {
                $connection->update(['encryption_locked_at' => now()]);
            }
            $file = $user->files()->create([
                'encryption_header' => $header,
                'metadata' => $protected ? ['protected_metadata' => $data->protectedMetadata] : ($data->encrypted ? ['plaintext_mime' => $data->plaintextMime ?? 'application/octet-stream'] : null),
                'directory_id' => $directory?->id,
                'telegram_account_id' => $connection->telegram_account_id,
                'telegram_connection_id' => $connection->id,
                'telegram_chat_id' => $connection->chat_id,
                'name' => $this->deriveName($uploaded->getClientOriginalName()),
                'original_name' => (string) $uploaded->getClientOriginalName(),
                'extension' => mb_substr($uploaded->getClientOriginalExtension(), 0, 16) ?: null,
                'mime_type' => $mimeType,
                'file_type' => FileType::fromMime($mimeType),
                'size' => (int) $uploaded->getSize(),
                'checksum' => $checksum,
                'status' => FileStatus::Pending,
            ]);

            return $user->fileOperations()->create([
                'file_id' => $file->id,
                'telegram_account_id' => $connection->telegram_account_id,
                'telegram_connection_id' => $connection->id,
                'operation' => FileOperationType::Upload,
                'status' => FileOperationStatus::Pending,
                'progress' => 0,
                'reserved_temp_bytes' => 0,
                'metadata' => [
                    'file_name' => $file->name,
                    'upload_source' => 'worker_bridge',
                    'random_id' => random_int(1, PHP_INT_MAX),
                    'original_name' => (string) $uploaded->getClientOriginalName(),
                ],
            ]);
        });

        try {
            $this->sendTelegramUploadStream->handle(new SendTelegramUploadStreamData(
                operationId: $operation->id,
                sourcePath: $sourcePath,
                size: (int) $uploaded->getSize(),
            ));
        } catch (Throwable) {
            $failedByTransport = false;
            $operation = DB::transaction(function () use ($operation, &$failedByTransport): FileOperation {
                $current = FileOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
                if (in_array($current->status, [FileOperationStatus::Completed, FileOperationStatus::Cancelled, FileOperationStatus::Failed, FileOperationStatus::NeedsReview], true)
                    || ! empty($current->metadata['send_started_at'])
                    || ! empty($current->metadata['cancel_requested_at'])) {
                    return $current;
                }

                $failedByTransport = true;
                $current->file?->forceFill(['status' => FileStatus::Failed])->save();
                $current->forceFill([
                    'status' => FileOperationStatus::Failed,
                    'error' => 'The Telegram worker could not receive this upload. Select the file and upload it again.',
                    'completed_at' => now(),
                    'reserved_temp_bytes' => 0,
                ])->save();

                return $current;
            });

            if ($failedByTransport) {
                broadcast(new FileUploadFailed($operation));
            }
        }

        return $operation->refresh();
    }

    /**
     * Keep the original name as the virtual file name (collision handling
     * happens per-directory at rename/move time).
     */
    private function deriveName(?string $original): string
    {
        $name = trim((string) $original);

        return $name === '' ? 'file-'.(string) Str::ulid() : $name;
    }
}
