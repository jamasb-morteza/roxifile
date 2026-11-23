<?php

namespace Tests\Feature\Files;

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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AsyncDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_download_is_prepared_in_the_background_and_only_the_owner_can_retrieve_it(): void
    {
        $connection = TelegramConnection::factory()->connected()->create();
        $file = File::factory()->uploaded()->create([
            'user_id' => $connection->user_id, 'telegram_account_id' => $connection->telegram_account_id,
            'telegram_connection_id' => $connection->id, 'file_type' => 'audio', 'mime_type' => 'audio/mpeg',
        ]);
        $this->app->bind(StorageManager::class, fn () => new class extends StorageManager
        {
            public function __construct() {}

            public function for(TelegramConnection $connection): TelegramStorageDriver
            {
                return new FakeTelegramStorageDriver;
            }
        });
        Queue::fake([TelegramOperationJob::class]);

        $response = $this->actingAs($connection->user)->postJson('/files/'.$file->id.'/prepare-download')->assertAccepted();

        $operationId = $response->json('operation_id');
        Queue::assertPushed(TelegramOperationJob::class, fn ($job): bool => $job->operationId === $operationId);
        $this->getJson('/files/'.$file->id.'/download?operation_id='.$operationId)->assertConflict();
        (new TelegramOperationJob($operationId))->handle(app(TelegramOperationProcessor::class));
        $download = $this->get('/files/'.$file->id.'/download?operation_id='.$operationId)->assertOk();
        $this->assertSame('fake-telegram-content-'.$file->id, $download->streamedContent());
        $preview = $this->get('/files/'.$file->id.'/download?operation_id='.$operationId.'&inline=1')->assertOk();
        $this->assertSame('fake-telegram-content-'.$file->id, $preview->streamedContent());
        $this->assertDatabaseMissing('media_playback_events', ['file_id' => $file->id]);
        $this->actingAs(User::factory()->create())
            ->getJson('/files/'.$file->id.'/download?operation_id='.$operationId)->assertForbidden();
    }

    public function test_safe_text_preview_is_inline_and_active_content_stays_an_attachment(): void
    {
        $file = File::factory()->uploaded()->create(['mime_type' => 'text/plain', 'name' => 'note.txt']);
        Storage::disk('local')->put('telegram-download/preview', '<script>not executed</script>');
        $operation = FileOperation::factory()->create([
            'user_id' => $file->user_id, 'file_id' => $file->id, 'operation' => 'download', 'status' => 'completed',
            'metadata' => ['downloaded_path' => 'telegram-download/preview', 'expires_at' => now()->addMinutes(15)->toIso8601String()],
        ]);

        $preview = $this->actingAs($file->user)->get('/files/'.$file->id.'/download?operation_id='.$operation->id.'&inline=1')->assertOk();

        $this->assertStringStartsWith('inline;', $preview->headers->get('Content-Disposition'));
        $preview->assertHeader('X-Content-Type-Options', 'nosniff');
        $preview->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'");
        $file->update(['mime_type' => 'text/html']);
        $unsafe = $this->get('/files/'.$file->id.'/download?operation_id='.$operation->id.'&inline=1')->assertOk();
        $this->assertStringStartsWith('attachment;', $unsafe->headers->get('Content-Disposition'));
    }

    public function test_prepared_media_supports_ranges_and_rejects_expired_or_foreign_requests(): void
    {
        $file = File::factory()->uploaded()->create(['mime_type' => 'video/mp4']);
        Storage::disk('local')->put('telegram-download/range', '0123456789');
        $operation = FileOperation::factory()->create([
            'user_id' => $file->user_id, 'file_id' => $file->id, 'operation' => 'download', 'status' => 'completed',
            'metadata' => ['downloaded_path' => 'telegram-download/range', 'expires_at' => now()->addMinutes(15)->toIso8601String()],
        ]);
        $url = '/files/'.$file->id.'/download?operation_id='.$operation->id.'&inline=1';
        $this->actingAs($file->user);

        foreach ([['bytes=0-2', '012', 'bytes 0-2/10'], ['bytes=4-6', '456', 'bytes 4-6/10'], ['bytes=-2', '89', 'bytes 8-9/10']] as [$range, $content, $contentRange]) {
            $response = $this->withHeader('Range', $range)->get($url)->assertStatus(206);
            $response->assertHeader('Content-Range', $contentRange);
            $this->assertSame($content, $response->streamedContent());
        }
        $this->withHeader('Range', 'bytes=50-70')->get($url)->assertStatus(416);
        $this->actingAs(User::factory()->create())->withHeader('Range', 'bytes=0-2')->getJson($url)->assertForbidden();
        $this->travel(16)->minutes();
        $this->actingAs($file->user)->getJson($url)->assertGone();
    }
}
