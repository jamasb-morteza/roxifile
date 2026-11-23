<?php

namespace Tests\Feature\Files;

use App\Domains\Files\Enums\FileStatus;
use App\Domains\Files\Models\Directory;
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
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DownloadDeleteTest extends TestCase
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

    public function test_download_streams_the_telegram_object(): void
    {
        $file = $this->storedFile();

        $this->actingAs($this->user)->postJson("/files/{$file->id}/prepare-download")->assertAccepted();
        $response = $this->get("/files/{$file->id}/download");

        $response->assertOk();
        $this->assertStringContainsString('fake-telegram-content', (string) $response->streamedContent());

        $operation = FileOperation::query()
            ->where('file_id', $file->id)
            ->where('operation', 'download')
            ->firstOrFail();

        $this->assertSame('completed', $operation->status->value);
        $this->assertSame(1, $this->countCalls('download'));
    }

    public function test_download_denied_for_other_users(): void
    {
        $file = $this->storedFile();
        $other = User::factory()->create();

        $this->actingAs($other)->get("/files/{$file->id}/download")->assertForbidden();
    }

    public function test_trash_keeps_the_telegram_object_and_allows_download_after_restore(): void
    {
        $file = $this->storedFile();

        $response = $this->actingAs($this->user)->deleteJson("/files/{$file->id}");

        $response->assertOk()->assertJsonPath('deleted', true)->assertJsonPath('operation_id', null);
        $this->assertSoftDeleted($file);
        $this->assertSame(0, $this->countCalls('delete'));
        $this->postJson("/files/{$file->id}/restore")->assertOk();
        $this->postJson("/files/{$file->id}/prepare-download")->assertAccepted();
        $download = $this->get("/files/{$file->id}/download")->assertOk();
        $this->assertSame('fake-telegram-content-'.$file->id, $download->streamedContent());
    }

    public function test_trash_retains_a_file_which_has_not_finished_uploading(): void
    {
        $file = File::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->deleteJson("/files/{$file->id}")
            ->assertOk()->assertJsonPath('operation_id', null);

        $this->assertSoftDeleted($file);
        $this->assertSame(0, $this->countCalls('delete'));
    }

    public function test_restore_returns_a_trashed_file(): void
    {
        $file = $this->storedFile();

        $this->actingAs($this->user)->deleteJson("/files/{$file->id}")->assertOk();
        $this->actingAs($this->user)->postJson("/files/{$file->id}/restore")->assertOk();

        $file->refresh();
        $this->assertFalse($file->trashed());
        $this->assertSame(FileStatus::Uploaded, $file->status);
    }

    public function test_purge_removes_the_row(): void
    {
        $file = $this->storedFile();

        $this->actingAs($this->user)->deleteJson("/files/{$file->id}")->assertOk();
        $this->actingAs($this->user)->deleteJson("/files/{$file->id}/purge")->assertOk();

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
    }

    public function test_purge_keeps_the_mapping_until_the_background_delete_completes(): void
    {
        $file = $this->storedFile();
        $this->actingAs($this->user)->deleteJson("/files/{$file->id}")->assertOk();
        Queue::fake([TelegramOperationJob::class]);

        $response = $this->deleteJson("/files/{$file->id}/purge")
            ->assertAccepted()->assertJsonPath('purged', false);

        $this->assertSoftDeleted($file);
        $operationId = $response->json('operation_id');
        Queue::assertPushed(TelegramOperationJob::class, fn ($job): bool => $job->operationId === $operationId);
        (new TelegramOperationJob($operationId))->handle(app(TelegramOperationProcessor::class));
        $this->assertModelMissing($file);
        $this->assertSame(1, $this->countCalls('delete'));
    }

    public function test_directory_purge_waits_for_deleted_descendants_to_be_removed_from_telegram(): void
    {
        $root = Directory::factory()->create(['user_id' => $this->user->id]);
        $child = Directory::factory()->create(['user_id' => $this->user->id, 'parent_id' => $root->id]);
        $file = $this->storedFile();
        $file->update(['directory_id' => $child->id]);
        $this->actingAs($this->user)->deleteJson('/directories/'.$child->id)->assertOk();
        $this->deleteJson('/directories/'.$root->id)->assertOk();
        Queue::fake([TelegramOperationJob::class]);

        $this->deleteJson('/directories/'.$root->id.'/purge')->assertAccepted()->assertJsonPath('purged', false);

        $this->assertSoftDeleted($file);
        Queue::assertPushed(TelegramOperationJob::class);
        $operation = FileOperation::where('file_id', $file->id)->firstOrFail();
        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));
        $this->assertModelMissing($file);
        $this->assertModelMissing($root);
        $this->assertModelMissing($child);
    }

    public function test_guests_cannot_delete(): void
    {
        $file = $this->storedFile();

        $this->deleteJson("/files/{$file->id}")->assertUnauthorized();
    }

    private function storedFile(): File
    {
        return File::factory()->uploaded()->create([
            'user_id' => $this->user->id,
            'telegram_account_id' => $this->connection->telegram_account_id,
            'telegram_connection_id' => $this->connection->id,
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
