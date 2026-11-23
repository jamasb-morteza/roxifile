<?php

namespace App\Domains\Telegram\Jobs;

use App\Domains\Files\Actions\ManageFileOperation;
use App\Domains\Files\Enums\FileOperationStatus;
use App\Domains\Files\Enums\FileOperationType;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Telegram\Runtime\ErrorClassifier;
use App\Domains\Telegram\Runtime\OperationLease;
use App\Domains\Telegram\Runtime\TelegramErrors;
use App\Domains\Telegram\Runtime\TelegramOperationProcessor;
use App\Domains\Telegram\Runtime\TelegramUploadStreamBridge;
use App\Domains\Telegram\Runtime\TelegramUploadStreamException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Transport envelope for a Telegram operation (spec §46).
 *
 * The payload carries ONLY the operation id — never binaries, temp paths, or
 * Telegram identifiers (Rule 5). Retries are tracked on the FileOperation row
 * (source of truth) and re-dispatched with exponential backoff; a completed
 * operation is never re-executed (spec §48).
 */
#[Connection('redis')]
#[Queue('telegram')]
class TelegramOperationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $operationId,
    ) {}

    public function handle(TelegramOperationProcessor $processor): void
    {
        $operation = FileOperation::query()->find($this->operationId);

        if ($operation === null) {
            app(TelegramUploadStreamBridge::class)->fail(
                $this->operationId,
                new TelegramUploadStreamException('The upload operation no longer exists.'),
            );

            return;
        }

        // Idempotency: nothing to do for ops that already reached a terminal state.
        if (in_array($operation->status, [
            FileOperationStatus::Completed,
            FileOperationStatus::Cancelled,
            FileOperationStatus::Failed,
            FileOperationStatus::NeedsReview,
        ], true)) {
            if (($operation->metadata['upload_source'] ?? null) === 'worker_bridge') {
                if ($operation->status === FileOperationStatus::Completed) {
                    app(TelegramUploadStreamBridge::class)->finish($operation->id);
                } else {
                    app(TelegramUploadStreamBridge::class)->fail(
                        $operation->id,
                        new TelegramUploadStreamException('The upload operation is no longer active.'),
                    );
                }
            }

            return;
        }

        $lease = app(OperationLease::class);
        if (! $lease->acquire($operation->id)) {
            return;
        }
        $operation->refresh();

        try {
            if (in_array($operation->operation, [FileOperationType::Upload, FileOperationType::Copy], true)
                && ! empty($operation->metadata['send_started_at']) && empty($operation->metadata['upload_result'])) {
                if (($operation->metadata['upload_source'] ?? null) === 'worker_bridge') {
                    app(TelegramUploadStreamBridge::class)->fail(
                        $operation->id,
                        new TelegramUploadStreamException('The upload outcome requires review.'),
                    );
                }
                $lease->mutate($operation->id, function (FileOperation $current): void {
                    $current->update([
                        'status' => FileOperationStatus::NeedsReview, 'error' => 'Telegram may have received this file. Review the upload before retrying.',
                    ]);
                });

                return;
            }
            $processor->process($operation);
        } catch (Throwable $e) {
            if (! $lease->owns($operation->id)) {
                return;
            }

            $operation->refresh();
            if (in_array($operation->status, [FileOperationStatus::Completed, FileOperationStatus::Cancelled, FileOperationStatus::Failed, FileOperationStatus::NeedsReview], true)) {
                Log::warning('Telegram notification failed after the storage outcome was recorded.', [
                    'operation_id' => $operation->id, 'error' => TelegramErrors::message($e->getMessage()),
                ]);

                return;
            }
            if (! empty($operation->metadata['cancel_requested_at']) && empty($operation->metadata['send_started_at'])) {
                if (($operation->metadata['upload_source'] ?? null) === 'worker_bridge') {
                    app(TelegramUploadStreamBridge::class)->fail(
                        $operation->id,
                        new TelegramUploadStreamException('The upload was cancelled.'),
                    );
                }
                $lease->mutate($operation->id, fn (FileOperation $current) => app(ManageFileOperation::class)->finishCancellation($current));

                return;
            }
            if (in_array($operation->operation, [FileOperationType::Upload, FileOperationType::Copy], true)
                && ! empty($operation->metadata['send_started_at']) && empty($operation->metadata['upload_result'])) {
                $lease->mutate($operation->id, function (FileOperation $current): void {
                    $current->update([
                        'status' => FileOperationStatus::NeedsReview,
                        'error' => 'Telegram may have received this file. Review the upload before retrying.',
                    ]);
                });

                return;
            }

            if ($operation->operation === FileOperationType::Upload
                && ($operation->metadata['upload_source'] ?? null) === 'worker_bridge'
                && empty($operation->metadata['upload_result'])) {
                app(TelegramUploadStreamBridge::class)->fail($operation->id, $e);
                $errorLabel = $e instanceof TelegramUploadStreamException
                    ? 'The upload stream ended before Telegram accepted the file. Select the file and upload it again.'
                    : ErrorClassifier::classify($e)->errorLabel($e->getMessage());
                $processor->fail($operation, $errorLabel);

                return;
            }

            $decision = ErrorClassifier::classify($e);
            $error = $decision->errorLabel($e->getMessage());
            Log::warning('Telegram operation failed.', [
                'operation_id' => $operation->id, 'kind' => $decision->kind, 'error' => $error,
            ]);

            $maxAttempts = max(1, (int) config('telegram.worker.max_attempts', 5));

            if ($decision->retryable && $operation->attempts < $maxAttempts) {
                $delay = $decision->delaySeconds ?? $this->backoffSeconds($operation->attempts);
                $operation = $processor->markRetrying($operation, $error, $delay);

                self::dispatch($this->operationId)
                    ->delay($operation->available_at);

                return;
            }

            $processor->fail($operation, $error);
        } finally {
            $lease->release($operation->id);
        }
    }

    private function backoffSeconds(int $attempt): int
    {
        return min(60, 5 * 2 ** max(0, $attempt - 1));
    }
}
