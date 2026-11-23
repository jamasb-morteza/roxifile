<?php

namespace Tests\Feature\Files;

use App\Domains\Files\Actions\ManageFileOperation;
use App\Domains\Files\Enums\FileOperationStatus;
use App\Domains\Files\Enums\FileStatus;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Contracts\TelegramStorageDriver;
use App\Domains\Telegram\Drivers\FakeTelegramStorageDriver;
use App\Domains\Telegram\Jobs\TelegramOperationJob;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\TelegramOperationProcessor;
use App\Domains\Telegram\Runtime\TelegramUploadStreamBridge;
use App\Domains\Telegram\Runtime\TelegramUploadStreamException;
use App\Domains\Telegram\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class UploadFlowTest extends TestCase
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

                public function for(TelegramConnection $connection): TelegramStorageDriver
                {
                    return $this->fake;
                }
            };
        });
    }

    public function test_upload_creates_file_operation_and_completes(): void
    {
        $response = $this->upload(UploadedFile::fake()->create('report.pdf', 120));

        $response->assertStatus(202);
        $response->assertJsonStructure(['operation_id', 'file_id', 'status']);

        $file = File::query()->findOrFail($response->json('file_id'));
        $this->assertSame($this->user->id, $file->user_id);
        $this->assertSame(FileStatus::Uploaded, $file->status);
        $this->assertSame('application/pdf', $file->mime_type);
        $this->assertSame('report.pdf', $file->original_name);
        $this->assertNotNull($file->telegram_message_id);
        $this->assertNotNull($file->checksum);

        $operation = FileOperation::query()->findOrFail($response->json('operation_id'));
        $this->assertSame(FileOperationStatus::Completed, $operation->status);
        $this->assertSame(100, $operation->progress);
        $this->assertNull($operation->metadata['temp_path']);
    }

    public function test_mtproto_accepts_a_200_mib_upload_at_the_request_boundary(): void
    {
        Queue::fake([TelegramOperationJob::class]);

        $response = $this->upload(UploadedFile::fake()->create('large.mp4', 204800));

        $response->assertAccepted();
        $file = File::query()->findOrFail($response->json('file_id'));
        $this->assertSame(200 * 1024 * 1024, $file->size);
        Queue::assertPushed(TelegramOperationJob::class);
    }

    public function test_mtproto_rejects_an_upload_over_200_mib(): void
    {
        $this->upload(UploadedFile::fake()->create('too-large.mp4', 204801))
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('files', 0);
    }

    public function test_public_bot_uploads_remain_capped_at_20_mib(): void
    {
        $this->connection->update(['method' => 'bot']);

        $this->upload(UploadedFile::fake()->create('bot-too-large.mp4', 20481))
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('files', 0);
    }

    public function test_file_manager_displays_the_200_mib_upload_limit(): void
    {
        $this->actingAs($this->user)->get('/files')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('files/Index')
                ->where('max_upload_bytes', 200 * 1024 * 1024)
                ->etc());
    }

    public function test_temp_file_is_deleted_after_success(): void
    {
        $before = Storage::disk('local')->allFiles('telegram-upload');

        $response = $this->upload(UploadedFile::fake()->create('clip.mp4', 50));

        $response->assertStatus(202);

        // Rule 13: the private temp copy is gone once the operation completes.
        $after = Storage::disk('local')->allFiles('telegram-upload');

        $this->assertSame($before, $after, 'Temporary upload files must be deleted after success.');
    }

    public function test_large_upload_does_not_create_a_private_full_file_copy_while_worker_consumes_it(): void
    {
        $contents = str_repeat("\x00\xA5", 3 * 1024 * 1024);
        $temporaryFilesDuringUpload = null;
        $this->driver->duringUpload = function () use (&$temporaryFilesDuringUpload): void {
            $temporaryFilesDuringUpload = Storage::disk('local')->allFiles('telegram-upload');
        };

        $response = $this->upload(UploadedFile::fake()->createWithContent('large.bin', $contents));

        $response->assertAccepted();
        $this->assertSame([], $temporaryFilesDuringUpload);
        $this->assertSame($contents, array_values($this->driver->contents)[0]);
    }

    public function test_client_disconnect_fails_upload_and_releases_bridge_session(): void
    {
        $transport = $this->telegramUploadStreamTransport;
        $transport->disconnectAfterBytes = 65536;
        $response = $this->upload(UploadedFile::fake()->createWithContent('interrupted.bin', str_repeat('x', 131072)));

        $response->assertAccepted();
        $operation = FileOperation::query()->findOrFail($response->json('operation_id'));
        $this->assertSame(FileOperationStatus::Failed, $operation->status);
        $this->assertSame(FileStatus::Failed, $operation->file->status);
        $this->assertCount(1, array_filter($this->driver->calls, fn (array $call): bool => $call['method'] === 'upload'));
        $this->expectException(TelegramUploadStreamException::class);
        app(TelegramUploadStreamBridge::class)->consume($operation->id);
    }

    public function test_cancelled_upload_releases_the_worker_stream_without_marking_the_file_ready(): void
    {
        $this->driver->duringUpload = function (): void {
            $operation = FileOperation::query()->latest('id')->firstOrFail();
            app(ManageFileOperation::class)->cancel($operation);
        };

        $response = $this->upload(UploadedFile::fake()->createWithContent('cancelled.bin', str_repeat('z', 131072)));

        $response->assertAccepted();
        $operation = FileOperation::query()->findOrFail($response->json('operation_id'));
        $this->assertSame(FileOperationStatus::Cancelled, $operation->status);
        $this->assertSame(FileStatus::Failed, $operation->file->status);
        $this->assertSame([], $this->driver->contents);
        $this->expectException(TelegramUploadStreamException::class);
        app(TelegramUploadStreamBridge::class)->consume($operation->id);
    }

    public function test_failed_stream_upload_cannot_be_retried_without_the_original_request_body(): void
    {
        $this->driver->throwOnNext = new \RuntimeException('simulated worker failure');
        $response = $this->upload(UploadedFile::fake()->createWithContent('retry.bin', str_repeat('y', 64)));

        $response->assertAccepted();
        $operation = FileOperation::query()->findOrFail($response->json('operation_id'));
        $this->assertSame(FileOperationStatus::Failed, $operation->status);
        $this->assertSame(FileStatus::Failed, $operation->file->status);
        $this->assertSame(1, $operation->attempts);
        $this->assertCount(1, array_filter($this->driver->calls, fn (array $call): bool => $call['method'] === 'upload'));
        $this->actingAs($this->user)->getJson('/file-operations')
            ->assertOk()->assertJsonPath('operations.0.can_retry_operation', false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The original upload expired. Upload the file again.');
        app(ManageFileOperation::class)->retry($operation);
    }

    public function test_worker_failure_before_consuming_stream_releases_bridge_session(): void
    {
        Queue::fake([TelegramOperationJob::class]);

        $response = $this->upload(UploadedFile::fake()->createWithContent('driver-error.txt', 'bytes'));

        $response->assertAccepted();
        $operation = FileOperation::query()->findOrFail($response->json('operation_id'));
        $this->app->bind(StorageManager::class, function (): StorageManager {
            return new class extends StorageManager
            {
                public function __construct() {}

                public function for(TelegramConnection $connection): TelegramStorageDriver
                {
                    throw new \RuntimeException('The storage driver is unavailable.');
                }
            };
        });

        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));

        $operation->refresh();
        $this->assertSame(FileOperationStatus::Failed, $operation->status);
        $this->assertSame(FileStatus::Failed, $operation->file->status);
        $this->expectException(TelegramUploadStreamException::class);
        app(TelegramUploadStreamBridge::class)->consume($operation->id);
    }

    public function test_duplicate_job_delivery_never_duplicates_the_upload(): void
    {
        $response = $this->upload(UploadedFile::fake()->create('doc.txt', 10));

        $response->assertStatus(202);

        $operationId = $response->json('operation_id');
        $uploadsBefore = $this->countCalls('upload');

        // Duplicate delivery (spec §48): a completed operation is skipped.
        TelegramOperationJob::dispatch($operationId);

        $this->assertSame($uploadsBefore, $this->countCalls('upload'));
        $this->assertSame(1, File::query()->count());
    }

    public function test_rejects_connections_owned_by_other_users(): void
    {
        $foreignConnection = TelegramConnection::factory()->connected()->create();

        $response = $this->upload(
            UploadedFile::fake()->create('secret.txt', 10),
            $foreignConnection->id,
        );

        $response->assertForbidden();
        $this->assertSame(0, File::query()->count());
    }

    public function test_rejects_disconnected_connections(): void
    {
        $this->connection->update(['status' => 'disconnected']);

        $response = $this->upload(UploadedFile::fake()->create('doc.txt', 10));

        $response->assertForbidden();
    }

    public function test_validates_required_fields(): void
    {
        $this->actingAs($this->user)
            ->postJson('/files/upload', ['connection_id' => $this->connection->id])
            ->assertStatus(422);

        // A missing/unknown connection fails ownership authorization first.
        $this->actingAs($this->user)
            ->postJson('/files/upload', [])
            ->assertStatus(403);
    }

    public function test_guests_cannot_upload(): void
    {
        $this->postJson('/files/upload', [])->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $additional
     * @return TestResponse
     */
    private function upload(UploadedFile $file, int|string|null $connectionId = null)
    {
        return $this->actingAs($this->user)->post('/files/upload', [
            'file' => $file,
            'connection_id' => $connectionId ?? $this->connection->id,
        ]);
    }

    private function countCalls(string $method): int
    {
        return count(array_filter(
            $this->driver->calls,
            fn (array $call): bool => $call['method'] === $method,
        ));
    }
}
