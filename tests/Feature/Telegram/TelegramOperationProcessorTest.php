<?php

namespace Tests\Feature\Telegram;

use App\Domains\Files\Enums\FileOperationStatus;
use App\Domains\Files\Enums\FileOperationType;
use App\Domains\Files\Enums\FileStatus;
use App\Domains\Files\Events\FileUploadCompleted;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Contracts\TelegramStorageDriver;
use App\Domains\Telegram\Drivers\FakeTelegramStorageDriver;
use App\Domains\Telegram\Jobs\TelegramOperationJob;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\TelegramOperationProcessor;
use App\Domains\Telegram\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Worker-side operation processing (spec §40-§42, §48): success, transient
 * retry, permanent failure, idempotent skip, and temp-file hygiene.
 */
class TelegramOperationProcessorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private TelegramConnection $connection;

    private FakeTelegramStorageDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->connection = TelegramConnection::factory()->connected()->create([
            'user_id' => $this->user->id,
        ]);

        $this->driver = new FakeTelegramStorageDriver;

        $this->app->bind(StorageManager::class, function (): StorageManager {
            return new class($this->driver) extends StorageManager
            {
                public function __construct(private readonly FakeTelegramStorageDriver $fake) {}

                public function for(TelegramConnection $conn): TelegramStorageDriver
                {
                    return $this->fake;
                }
            };
        });

        // Intercept the job's own re-dispatches; handles run explicitly.
        Queue::fake();
    }

    private function pendingUploadOperation(): FileOperation
    {
        $file = File::factory()->create([
            'user_id' => $this->user->id,
            'telegram_account_id' => $this->connection->telegram_account_id,
            'telegram_connection_id' => $this->connection->id,
            'size' => strlen('binary-payload'),
        ]);

        Storage::disk('local')->put('telegram-upload/test-payload.bin', 'binary-payload');

        return FileOperation::factory()->create([
            'user_id' => $this->user->id,
            'file_id' => $file->id,
            'telegram_account_id' => $this->connection->telegram_account_id,
            'telegram_connection_id' => $this->connection->id,
            'operation' => FileOperationType::Upload,
            'status' => FileOperationStatus::Pending,
            'attempts' => 0,
            'metadata' => ['temp_path' => 'telegram-upload/test-payload.bin'],
        ]);
    }

    public function test_transient_failure_marks_retrying_and_requeues(): void
    {
        $operation = $this->pendingUploadOperation();
        $this->driver->throwOnNext = new \RuntimeException('connection reset by peer');

        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));

        $operation->refresh();

        $this->assertSame(FileOperationStatus::Retrying, $operation->status);
        $this->assertSame(1, $operation->attempts);
        $this->assertNotNull($operation->error);

        // The re-dispatch carries only the operation id (Rule 5).
        Queue::assertPushed(TelegramOperationJob::class, fn (TelegramOperationJob $job): bool => $job->operationId === $operation->id);
    }

    public function test_retry_after_transient_failure_completes_the_upload(): void
    {
        $operation = $this->pendingUploadOperation();

        $this->driver->throwOnNext = new \RuntimeException('connection reset by peer');
        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));

        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));
        $this->assertSame(1, $this->countCalls('upload'), 'An early duplicate cannot bypass backoff.');
        $this->travel(61)->seconds();

        // Second eligible delivery succeeds.
        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));

        $operation->refresh();

        $this->assertSame(FileOperationStatus::Completed, $operation->status);
        $this->assertSame(2, $operation->attempts);

        // First attempt failed, second succeeded — both reached the driver.
        $this->assertSame(2, $this->countCalls('upload'));

        $file = $operation->file()->withTrashed()->firstOrFail();
        $this->assertSame(FileStatus::Uploaded, $file->status);
        $this->assertNotNull($file->telegram_message_id);

        // Rule 13: the temp copy is gone after success.
        $this->assertFalse(Storage::disk('local')->exists('telegram-upload/test-payload.bin'));
    }

    public function test_permanent_failure_fails_the_operation_and_the_file(): void
    {
        $operation = $this->pendingUploadOperation();
        $this->driver->throwOnNext = new \RuntimeException('peer_id_invalid');

        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));

        $operation->refresh();

        $this->assertSame(FileOperationStatus::Failed, $operation->status);
        $this->assertNotNull($operation->completed_at);
        $this->assertSame(FileStatus::Failed, $operation->file()->firstOrFail()->status);

        // Permanent failures are never re-queued (spec §42).
        Queue::assertNotPushed(TelegramOperationJob::class);
    }

    public function test_completed_operations_are_never_reprocessed(): void
    {
        $operation = $this->pendingUploadOperation();

        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));
        $operation->refresh();
        $this->assertSame(FileOperationStatus::Completed, $operation->status);

        // Duplicate delivery (spec §48): skipped entirely.
        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));

        $this->assertSame(1, $this->countCalls('upload'));
    }

    public function test_a_duplicate_delivery_cannot_upload_while_the_first_delivery_is_running(): void
    {
        $operation = $this->pendingUploadOperation();
        $this->driver->duringUpload = function () use ($operation): void {
            (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));
        };

        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));

        $this->assertSame(1, $this->countCalls('upload'));
        $this->assertSame(FileOperationStatus::Completed, $operation->fresh()->status);
    }

    public function test_missing_temp_file_fails_without_flagging_telegram(): void
    {
        $file = File::factory()->create([
            'user_id' => $this->user->id,
            'telegram_account_id' => $this->connection->telegram_account_id,
            'telegram_connection_id' => $this->connection->id,
        ]);

        $operation = FileOperation::factory()->create([
            'user_id' => $this->user->id,
            'file_id' => $file->id,
            'operation' => FileOperationType::Upload,
            'status' => FileOperationStatus::Pending,
            'metadata' => ['temp_path' => 'telegram-upload/does-not-exist.bin'],
        ]);

        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));

        $operation->refresh();
        $this->assertSame(FileOperationStatus::Failed, $operation->status);
        $this->assertSame(0, $this->countCalls('upload'));
    }

    public function test_delete_operations_reach_the_driver(): void
    {
        $file = File::factory()->uploaded()->create([
            'user_id' => $this->user->id,
            'telegram_account_id' => $this->connection->telegram_account_id,
            'telegram_connection_id' => $this->connection->id,
        ]);

        $operation = FileOperation::factory()->create([
            'user_id' => $this->user->id,
            'file_id' => $file->id,
            'operation' => FileOperationType::Delete,
            'metadata' => ['permanent' => true],
            'status' => FileOperationStatus::Pending,
        ]);

        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));

        $operation->refresh();

        $this->assertSame(FileOperationStatus::Completed, $operation->status);
        $this->assertSame(1, $this->countCalls('delete'));
    }

    private function countCalls(string $method): int
    {
        return count(array_filter(
            $this->driver->calls,
            fn (array $call): bool => $call['method'] === $method,
        ));
    }

    public function test_a_stale_executor_cannot_replace_the_new_owners_state(): void
    {
        $operation = $this->pendingUploadOperation();
        $this->driver->duringUpload = function () use ($operation): void {
            $operation->forceFill(['lease_token' => 'replacement-owner', 'lease_expires_at' => now()->addMinutes(5), 'status' => 'processing'])->save();
        };

        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));

        $operation->refresh();
        $this->assertSame('replacement-owner', $operation->lease_token);
        $this->assertSame('processing', $operation->status->value);
        $this->assertNull($operation->file->telegram_message_id);
        Storage::disk('local')->assertExists($operation->metadata['temp_path']);
    }

    public function test_redelivery_of_an_unknown_send_does_not_wait_for_recovery_to_prevent_resending(): void
    {
        $operation = $this->pendingUploadOperation();
        $operation->update(['metadata' => array_merge($operation->metadata, ['send_started_at' => now()->toIso8601String()])]);

        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));

        $this->assertSame('needs_review', $operation->fresh()->status->value);
        $this->assertSame(0, $this->countCalls('upload'));
    }

    public function test_a_completion_notification_failure_cannot_undo_storage_success(): void
    {
        $operation = $this->pendingUploadOperation();
        Event::listen(FileUploadCompleted::class, static function (): never {
            throw new \RuntimeException('connection refused while notifying');
        });

        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));

        $this->assertSame('completed', $operation->fresh()->status->value);
        $this->assertSame('uploaded', $operation->file->fresh()->status->value);
        $this->assertSame(1, $this->countCalls('upload'));
    }
}
