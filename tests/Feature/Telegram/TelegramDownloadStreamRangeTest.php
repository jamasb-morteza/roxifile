<?php

namespace Tests\Feature\Telegram;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use App\Domains\Files\Models\File;
use App\Domains\Telegram\Drivers\RoxiTelegramStorageDriver;
use App\Domains\Telegram\Runtime\TelegramDownloadStreamRequestHandler;
use App\Domains\Telegram\Runtime\TelegramDownloadStreamToken;
use App\Domains\Telegram\StorageManager;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TelegramDownloadStreamRangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_rejects_an_expired_download_capability(): void
    {
        $file = File::factory()->uploaded()->create(['size' => 10]);
        $expiresAt = time() - 1;
        $request = new Request(Mockery::mock(Client::class), 'GET', new Uri("http://127.0.0.1/files/{$file->id}/content"), [
            'X-Roxi-Download-User' => (string) $file->user_id,
            'X-Roxi-Download-Expires' => (string) $expiresAt,
            'X-Roxi-Download-Capability' => TelegramDownloadStreamToken::create($file->id, $file->user_id, $expiresAt),
        ]);
        $storageManager = Mockery::mock(StorageManager::class);
        $storageManager->shouldNotReceive('for');

        $response = (new TelegramDownloadStreamRequestHandler($storageManager))->handleRequest($request);

        $this->assertSame(401, $response->getStatus());
    }

    public function test_worker_rejects_a_file_above_the_mtproto_limit(): void
    {
        $file = File::factory()->uploaded()->create([
            'size' => TelegramDownloadStreamToken::MAX_FILE_BYTES + 1,
        ]);
        $expiresAt = time() + TelegramDownloadStreamToken::TTL_SECONDS;
        $request = new Request(Mockery::mock(Client::class), 'GET', new Uri("http://127.0.0.1/files/{$file->id}/content"), [
            'X-Roxi-Download-User' => (string) $file->user_id,
            'X-Roxi-Download-Expires' => (string) $expiresAt,
            'X-Roxi-Download-Capability' => TelegramDownloadStreamToken::create($file->id, $file->user_id, $expiresAt),
        ]);
        $storageManager = Mockery::mock(StorageManager::class);
        $storageManager->shouldNotReceive('for');

        $response = (new TelegramDownloadStreamRequestHandler($storageManager))->handleRequest($request);

        $this->assertSame(413, $response->getStatus());
    }

    public function test_worker_returns_content_range_for_an_unsatisfiable_range(): void
    {
        $file = File::factory()->uploaded()->create(['size' => 10]);
        $expiresAt = time() + TelegramDownloadStreamToken::TTL_SECONDS;
        $request = new Request(Mockery::mock(Client::class), 'GET', new Uri("http://127.0.0.1/files/{$file->id}/content"), [
            'X-Roxi-Download-User' => (string) $file->user_id,
            'X-Roxi-Download-Expires' => (string) $expiresAt,
            'X-Roxi-Download-Capability' => TelegramDownloadStreamToken::create($file->id, $file->user_id, $expiresAt),
            'Range' => 'bytes=10-12',
        ]);
        $driver = Mockery::mock(RoxiTelegramStorageDriver::class);
        $driver->shouldNotReceive('streamDownloadResponse');
        $storageManager = Mockery::mock(StorageManager::class);
        $storageManager->expects('for')->andReturn($driver);

        $response = (new TelegramDownloadStreamRequestHandler($storageManager))->handleRequest($request);

        $this->assertSame(416, $response->getStatus());
        $this->assertSame('bytes */10', $response->getHeader('content-range'));
    }

    public function test_worker_normalizes_a_suffix_range_before_requesting_telegram_bytes(): void
    {
        $file = File::factory()->uploaded()->create(['size' => 10]);
        $expiresAt = time() + TelegramDownloadStreamToken::TTL_SECONDS;
        $forwardedRange = null;
        $request = new Request(Mockery::mock(Client::class), 'GET', new Uri("http://127.0.0.1/files/{$file->id}/content"), [
            'X-Roxi-Download-User' => (string) $file->user_id,
            'X-Roxi-Download-Expires' => (string) $expiresAt,
            'X-Roxi-Download-Capability' => TelegramDownloadStreamToken::create($file->id, $file->user_id, $expiresAt),
            'Range' => 'bytes=-2',
        ]);
        $driver = Mockery::mock(RoxiTelegramStorageDriver::class);
        $driver->expects('streamDownloadResponse')->andReturnUsing(
            function (File $requestedFile, Request $workerRequest) use (&$forwardedRange, $file): Response {
                $this->assertTrue($requestedFile->is($file));
                $forwardedRange = $workerRequest->getHeader('range');

                return new Response(206, ['Content-Range' => 'bytes 8-9/10'], '89');
            });
        $storageManager = Mockery::mock(StorageManager::class);
        $storageManager->expects('for')->andReturn($driver);

        $response = (new TelegramDownloadStreamRequestHandler($storageManager))->handleRequest($request);

        $this->assertSame(206, $response->getStatus());
        $this->assertSame('bytes=8-9', $forwardedRange);
    }
}
