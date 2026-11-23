<?php

namespace Tests\Feature;

use App\Domains\Files\Events\FileDeleteCompleted;
use App\Domains\Files\Events\FileDeleteStarted;
use App\Domains\Files\Events\FileUploadCompleted;
use App\Domains\Files\Events\FileUploadProgress;
use App\Domains\Files\Events\FileUploadStarted;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Contracts\TelegramStorageDriver;
use App\Domains\Telegram\Drivers\FakeTelegramStorageDriver;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RealtimeEventsTest extends TestCase
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
    }

    public function test_upload_lifecycle_broadcasts_on_the_user_private_channel(): void
    {
        Event::fake([FileUploadStarted::class, FileUploadCompleted::class]);

        $response = $this->actingAs($this->user)->post('/files/upload', [
            'file' => UploadedFile::fake()->create('clip.mp4', 64),
            'connection_id' => $this->connection->id,
        ]);

        $response->assertStatus(202);
        $fileId = $response->json('file_id');

        foreach ([FileUploadStarted::class, FileUploadCompleted::class] as $eventClass) {
            Event::assertDispatched($eventClass, function ($event) use ($fileId): bool {
                $channels = collect($event->broadcastOn())
                    ->map(fn ($channel) => $channel->name)
                    ->all();

                if (! in_array('private-App.Models.User.'.$this->user->id, $channels, true)) {
                    return false;
                }

                // Payload rules (spec §34): minimal ids/statuses only.
                return ($event->broadcastWith()['file_id'] ?? null) === $fileId
                    && ! str_contains(json_encode($event->broadcastWith()), 'temp');
            });
        }
    }

    public function test_delete_lifecycle_broadcasts(): void
    {
        Event::fake([FileDeleteStarted::class, FileDeleteCompleted::class]);

        $file = File::factory()->uploaded()->create([
            'user_id' => $this->user->id,
            'telegram_account_id' => $this->connection->telegram_account_id,
            'telegram_connection_id' => $this->connection->id,
        ]);

        $this->actingAs($this->user)->deleteJson("/files/{$file->id}/purge")->assertOk();

        Event::assertDispatched(FileDeleteStarted::class);
        Event::assertDispatched(FileDeleteCompleted::class);
    }

    public function test_upload_progress_events_are_emitted_by_the_processor(): void
    {
        Event::fake([FileUploadProgress::class]);

        $this->actingAs($this->user)->post('/files/upload', [
            'file' => UploadedFile::fake()->create('doc.txt', 8),
            'connection_id' => $this->connection->id,
        ])->assertStatus(202);

        // The fake driver fires one (100%) progress callback.
        Event::assertDispatched(FileUploadProgress::class);
    }

    public function test_events_are_queued_on_the_telegram_queue(): void
    {
        Event::fake([FileUploadStarted::class]);

        $this->actingAs($this->user)->post('/files/upload', [
            'file' => UploadedFile::fake()->create('doc.txt', 8),
            'connection_id' => $this->connection->id,
        ])->assertStatus(202);

        // Worker-emitted events must ride the worker's broadcast queue (spec §34).
        Event::assertDispatched(FileUploadStarted::class, fn ($event): bool => $event->broadcastQueue === 'telegram');
    }

    public function test_queued_progress_keeps_its_original_payload_when_the_operation_changes(): void
    {
        $operation = FileOperation::factory()->create(['status' => 'uploading', 'progress' => 35]);
        $event = new FileUploadProgress($operation);
        $operation->update(['status' => 'completed', 'progress' => 100]);

        $queued = unserialize(serialize($event));

        $this->assertSame(35, $queued->broadcastWith()['progress']);
        $this->assertSame('uploading', $queued->broadcastWith()['status']);
        $this->assertSame('redis', $queued->connection);
    }
}
