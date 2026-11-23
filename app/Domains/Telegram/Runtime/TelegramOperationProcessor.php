<?php

namespace App\Domains\Telegram\Runtime;

use App\Domains\Files\Actions\PurgeDirectory;
use App\Domains\Files\Enums\FileOperationStatus;
use App\Domains\Files\Enums\FileOperationType;
use App\Domains\Files\Enums\FileStatus;
use App\Domains\Files\Events\FileDeleteCompleted;
use App\Domains\Files\Events\FileDeleteFailed;
use App\Domains\Files\Events\FileDeleteStarted;
use App\Domains\Files\Events\FileUploadCompleted;
use App\Domains\Files\Events\FileUploadFailed;
use App\Domains\Files\Events\FileUploadProgress;
use App\Domains\Files\Events\FileUploadStarted;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Telegram\Data\TelegramUploadResult;
use App\Domains\Telegram\Services\SyncTelegramMetadata;
use App\Domains\Telegram\StorageManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Executes queued Telegram operations in the worker process.
 *
 * The FileOperation row (PostgreSQL) is the single source of truth; the queue
 * is only a transport (design doc §4.2). Idempotent: a completed/cancelled
 * operation is never re-executed (spec §48).
 */
class TelegramOperationProcessor
{
    public function __construct(
        private readonly StorageManager $storageManager,
        private readonly WorkerHealth $health,
        private readonly OperationLease $lease,
        private readonly TelegramUploadStreamBridge $uploadStreams,
    ) {}

    /**
     * Process one operation. Throws on failure — the calling job decides
     * retry vs permanent failure via ErrorClassifier.
     */
    public function process(FileOperation $operation): void
    {
        // Idempotency gate (spec §48): completed/cancelled ops never re-run.
        if (in_array($operation->status, [FileOperationStatus::Completed, FileOperationStatus::Cancelled], true)) {
            return;
        }

        $operation = match ($operation->operation) {
            FileOperationType::Metadata => $this->metadata($operation),
            FileOperationType::Copy => $this->copy($operation),
            FileOperationType::Upload => $this->upload($operation),
            FileOperationType::Download => $this->download($operation),
            FileOperationType::Delete => $this->delete($operation),
            default => throw new \InvalidArgumentException('Unsupported operation type: '.$operation->operation->value),
        };

        if ($operation->status === FileOperationStatus::Completed) {
            $this->health->recordSuccessfulOperation();
        }
    }

    public function metadata(FileOperation $operation): FileOperation
    {
        $operation = $this->markProcessing($operation);
        app(SyncTelegramMetadata::class)->handle($operation);
        if ($operation->refresh()->status === FileOperationStatus::NeedsReview) {
            return $operation;
        }

        return $this->lease->mutate($operation->id, function (FileOperation $current): void {
            $current->forceFill(['status' => FileOperationStatus::Completed, 'progress' => 100, 'error' => null, 'completed_at' => now()])->save();
        });
    }

    public function copy(FileOperation $operation): FileOperation
    {
        $operation = $this->markProcessing($operation, FileOperationStatus::Downloading);
        $path = $operation->metadata['temp_path'] ?? null;
        if (is_array($operation->metadata['upload_result'] ?? null)) {
            return $this->upload($operation);
        }
        if (! is_string($path) || ! Storage::disk('local')->exists($path)) {
            $source = File::withTrashed()->where('user_id', $operation->user_id)->whereKey($operation->metadata['source_file_id'] ?? null)->firstOrFail();
            if ($source->status !== FileStatus::Uploaded) {
                throw new \InvalidArgumentException('The source is no longer available for copying.');
            }
            $staging = 'telegram-upload/'.Str::ulid();
            Storage::disk('local')->makeDirectory('telegram-upload');
            try {
                $this->storageManager->for($source->connection)->download($source, Storage::disk('local')->path($staging));
                $this->lease->throwIfCancelled($operation->id);
                $operation = $this->lease->updateMetadata($operation->id, ['temp_path' => $staging]);
            } catch (Throwable $error) {
                if (FileOperation::query()->find($operation->id)?->metadata['temp_path'] !== $staging) {
                    Storage::disk('local')->delete($staging);
                }
                throw $error;
            }
        }

        return $this->upload($operation);
    }

    /**
     * @throws Throwable when the upload fails (classified by the caller)
     */
    public function upload(FileOperation $operation): FileOperation
    {
        $operation = $operation->operation === FileOperationType::Copy
            ? $this->lease->mutate($operation->id, function (FileOperation $current): void {
                $current->update(['status' => FileOperationStatus::Uploading]);
            })
            : $this->markProcessing($operation, FileOperationStatus::Uploading);
        broadcast(new FileUploadStarted($operation));

        $file = $this->requireFile($operation);
        $tempPath = (string) ($operation->metadata['temp_path'] ?? '');

        $savedResult = $operation->metadata['upload_result'] ?? null;

        $driver = $this->storageManager->for($operation->connection);

        if (is_array($savedResult)) {
            $result = new TelegramUploadResult(
                (int) $savedResult['message_id'], $savedResult['media_id'] ?? null, $savedResult['file_id'] ?? null,
            );
        } else {
            $content = ($operation->metadata['upload_source'] ?? null) === 'worker_bridge'
                ? $this->uploadStreams->consume($operation->id)
                : $this->localUploadContent($tempPath, $file->size);

            try {
                $result = $driver->upload(
                    $operation,
                    $file,
                    $content,
                    function (int $bytes, int $total) use ($operation): void {
                        $progress = $total > 0 ? (int) floor($bytes / $total * 100) : 0;

                        $snapshot = $this->lease->mutate($operation->id, function (FileOperation $current) use ($progress): void {
                            if (empty($current->metadata['upload_result']) && ! empty($current->metadata['cancel_requested_at'])) {
                                throw new \DomainException('Transfer cancellation requested.');
                            }
                            $current->update(['progress' => min($progress, 99)]);
                        });
                        broadcast(new FileUploadProgress($snapshot));
                    },
                );
            } finally {
                $content->close();
                $this->uploadStreams->finish($operation->id);
            }
        }

        DB::transaction(function () use ($file, $result, $operation): void {
            FileOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $this->lease->assertOwned($operation->id);
            $operation->refresh();
            $file->forceFill([
                'status' => FileStatus::Uploaded,
                'metadata' => array_merge($file->metadata ?? [], ['telegram_metadata_owned_caption' => true]),
                'telegram_chat_id' => $file->telegram_chat_id ?? $file->connection?->chat_id,
                'telegram_message_id' => $result->telegramMessageId,
                'telegram_media_id' => $result->telegramMediaId ?? $file->telegram_media_id,
                'telegram_file_id' => $result->telegramFileId ?? $file->telegram_file_id,
            ])->save();

            $operation->forceFill([
                'status' => FileOperationStatus::Completed,
                'progress' => 100,
                'error' => null,
                'completed_at' => Carbon::now(),
                'metadata' => array_merge($operation->metadata ?? [], ['temp_path' => null]),
                'reserved_temp_bytes' => 0,
            ])->save();

        });

        if ($tempPath !== '') {
            Storage::disk('local')->delete($tempPath);
        }

        broadcast(new FileUploadCompleted($operation));

        return $operation;
    }

    private function localUploadContent(string $tempPath, int $size): TelegramUploadContent
    {
        if ($tempPath === '') {
            throw new \RuntimeException('The original upload source is no longer available. Upload the file again.');
        }

        $fullPath = Storage::disk('local')->path($tempPath);
        if (! is_file($fullPath)) {
            throw new \RuntimeException('The original upload source is no longer available. Upload the file again.');
        }

        return TelegramUploadContent::fromPath($fullPath, $size);
    }

    /**
     * @throws Throwable when the download fails (classified by the caller)
     */
    public function download(FileOperation $operation): FileOperation
    {
        $operation = $this->markProcessing($operation, FileOperationStatus::Downloading);

        $file = $this->requireFile($operation);
        $destination = 'telegram-download/'.(string) Str::ulid();

        // Drivers write directly to the absolute path — the directory must
        // exist before the download starts.
        if (! Storage::disk('local')->directoryExists('telegram-download')) {
            Storage::disk('local')->makeDirectory('telegram-download');
        }

        $driver = $this->storageManager->for($file->connection);

        try {
            $driver->download(
                $file,
                Storage::disk('local')->path($destination),
            );
            $operation = $this->lease->mutate($operation->id, function (FileOperation $current) use ($destination): void {
                if (! empty($current->metadata['cancel_requested_at'])) {
                    throw new \DomainException('Transfer cancellation requested.');
                }
                $current->forceFill([
                    'status' => FileOperationStatus::Completed, 'progress' => 100, 'error' => null,
                    'completed_at' => Carbon::now(),
                    'metadata' => array_merge($current->metadata ?? [], ['downloaded_path' => $destination, 'expires_at' => now()->addMinutes(15)->toIso8601String()]),
                ])->save();
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($destination);
            throw $e;
        }

        return $operation;
    }

    /**
     * @throws Throwable when the Telegram delete fails (classified by the caller)
     */
    public function delete(FileOperation $operation): FileOperation
    {
        $operation = $this->markProcessing($operation);
        broadcast(new FileDeleteStarted($operation));

        if (! ($operation->metadata['permanent'] ?? false)) {
            $operation->update(['status' => FileOperationStatus::Cancelled]);

            return $operation;
        }

        $file = $this->requireFile($operation);

        $driver = $this->storageManager->for($file->connection);

        $driver->delete($file);

        $event = DB::transaction(function () use ($operation, $file): FileDeleteCompleted {
            FileOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $this->lease->assertOwned($operation->id);
            $operation->forceFill([
                'status' => FileOperationStatus::Completed, 'error' => null, 'completed_at' => Carbon::now(),
            ])->save();
            $event = new FileDeleteCompleted($operation);
            $file->forceDelete();

            return $event;
        });
        broadcast($event);
        app(PurgeDirectory::class)->finishPending($operation->user_id);

        return $operation;
    }

    /**
     * Record permanent failure on the operation (and the file, for uploads).
     */
    public function fail(FileOperation $operation, string $error): void
    {
        $operation = $this->lease->mutate($operation->id, function (FileOperation $current) use ($error): void {
            $path = $current->metadata['temp_path'] ?? null;
            $current->forceFill([
                'status' => FileOperationStatus::Failed, 'error' => $error, 'completed_at' => Carbon::now(),
                'reserved_temp_bytes' => is_string($path) && Storage::disk('local')->exists($path) ? $current->reserved_temp_bytes : 0,
            ])->save();
            if (in_array($current->operation, [FileOperationType::Upload, FileOperationType::Copy], true)) {
                $current->file?->forceFill(['status' => FileStatus::Failed])->save();
            }
        });

        if (in_array($operation->operation, [FileOperationType::Upload, FileOperationType::Copy], true)) {
            broadcast(new FileUploadFailed($operation));
        }

        if ($operation->operation === FileOperationType::Delete) {
            broadcast(new FileDeleteFailed($operation));
        }
    }

    /**
     * Mark the op as retrying after a transient failure.
     */
    public function markRetrying(FileOperation $operation, string $error, int $delaySeconds): FileOperation
    {
        return $this->lease->mutate($operation->id, function (FileOperation $current) use ($error, $delaySeconds): void {
            $current->forceFill([
                'status' => FileOperationStatus::Retrying, 'error' => $error,
                'available_at' => now()->addSeconds($delaySeconds),
            ])->save();
        });
    }

    private function markProcessing(FileOperation $operation, FileOperationStatus $status = FileOperationStatus::Processing): FileOperation
    {
        return $this->lease->mutate($operation->id, function (FileOperation $current) use ($status): void {
            if (! empty($current->metadata['cancel_requested_at']) && empty($current->metadata['upload_result'])) {
                throw new \DomainException('Transfer cancellation requested.');
            }
            $current->forceFill(['status' => $status, 'attempts' => $current->attempts + 1, 'error' => null])->save();
        });
    }

    private function requireFile(FileOperation $operation): File
    {
        // The file is soft-deleted BEFORE a Telegram delete op runs, so the
        // lookup must include trashed rows (spec §9).
        $file = $operation->file()->withTrashed()->first();

        if ($file === null) {
            throw new \RuntimeException('Operation '.$operation->id.' has no associated file.');
        }

        return $file->fresh() ?? $file;
    }
}
