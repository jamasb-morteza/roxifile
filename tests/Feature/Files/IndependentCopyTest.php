<?php

namespace Tests\Feature\Files;

use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Telegram\Contracts\TelegramStorageDriver;
use App\Domains\Telegram\Drivers\FakeTelegramStorageDriver;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class IndependentCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_copy_preserves_content_after_the_original_is_permanently_deleted(): void
    {
        $connection = TelegramConnection::factory()->connected()->create();
        $driver = new FakeTelegramStorageDriver;
        $this->app->bind(StorageManager::class, fn () => new class($driver) extends StorageManager
        {
            public function __construct(private readonly FakeTelegramStorageDriver $driver) {}

            public function for(TelegramConnection $connection): TelegramStorageDriver
            {
                return $this->driver;
            }
        });
        $this->actingAs($connection->user);
        $upload = $this->postJson('/files/upload', [
            'connection_id' => $connection->id,
            'file' => UploadedFile::fake()->createWithContent('original.txt', 'independent copy bytes'),
        ])->assertAccepted();
        $source = File::query()->findOrFail($upload->json('file_id'));
        $foreignDirectory = Directory::factory()->create();
        $this->postJson('/files/'.$source->id.'/copy', ['directory_id' => $foreignDirectory->id])->assertUnprocessable();
        $copy = $this->postJson('/files/'.$source->id.'/copy', ['name' => 'copied.txt'])->assertAccepted();
        $destination = File::query()->findOrFail($copy->json('file_id'));
        $this->assertNotSame($source->telegram_message_id, $destination->telegram_message_id);
        foreach ([$source, $destination] as $file) {
            $preparation = $this->postJson('/files/'.$file->id.'/prepare-download')->assertAccepted();
            $download = $this->get('/files/'.$file->id.'/download?operation_id='.$preparation->json('operation_id'))->assertOk();
            $this->assertSame('independent copy bytes', $download->streamedContent());
        }
        $this->deleteJson('/files/'.$source->id.'/purge')->assertOk();
        $this->travel(16)->minutes();
        $preparation = $this->postJson('/files/'.$destination->id.'/prepare-download')->assertAccepted();
        $download = $this->get('/files/'.$destination->id.'/download?operation_id='.$preparation->json('operation_id'))->assertOk();
        $this->assertSame('independent copy bytes', $download->streamedContent());
    }

    public function test_copy_source_download_failures_consume_the_retry_budget(): void
    {
        $connection = TelegramConnection::factory()->connected()->create();
        $source = File::factory()->uploaded()->create(['user_id' => $connection->user_id, 'telegram_account_id' => $connection->telegram_account_id, 'telegram_connection_id' => $connection->id]);
        $driver = new FakeTelegramStorageDriver;
        $driver->throwOnNext = new \RuntimeException('connection reset by peer');
        $this->app->bind(StorageManager::class, fn () => new class($driver) extends StorageManager
        {
            public function __construct(private readonly FakeTelegramStorageDriver $driver) {}

            public function for(TelegramConnection $connection): TelegramStorageDriver
            {
                return $this->driver;
            }
        });
        config(['telegram.worker.max_attempts' => 1]);

        $response = $this->actingAs($connection->user)->postJson('/files/'.$source->id.'/copy', ['name' => 'retry-copy.txt'])->assertAccepted();

        $operation = FileOperation::query()->findOrFail($response->json('operation_id'));
        $this->assertSame('failed', $operation->status->value);
        $this->assertSame(1, $operation->attempts);
        $this->assertSame('uploaded', $source->fresh()->status->value);
    }
}
