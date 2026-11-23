<?php

namespace Tests\Feature\Telegram;

use Amp\Cancellation;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use App\Domains\Files\Models\File;
use App\Domains\Telegram\Drivers\RoxiTelegramStorageDriver;
use App\Domains\Telegram\Runtime\RoxiRuntime;
use GuzzleHttp\Psr7\Uri;
use Mockery;
use Revolt\EventLoop;
use Tests\TestCase;

class TelegramDownloadStreamCancellationTest extends TestCase
{
    public function test_disposing_the_worker_response_cancels_the_telegram_download(): void
    {
        $runtime = new class extends RoxiRuntime
        {
            public ?Cancellation $downloadCancellation = null;

            /** @return array<string, mixed> */
            public function getMessages(int $chatId, array $messageIds): array
            {
                return ['messages' => [[
                    '_' => 'message',
                    'id' => 42,
                    'media' => ['_' => 'messageMediaDocument'],
                ]]];
            }

            public function downloadToResponse(
                array $media,
                Request $request,
                int $size,
                string $mime,
                string $name,
                ?Cancellation $cancellation = null,
            ): Response {
                $this->downloadCancellation = $cancellation;

                return new Response(200, ['Content-Length' => (string) $size], '');
            }
        };
        $file = new File([
            'name' => 'large.mp4',
            'size' => 10,
            'mime_type' => 'video/mp4',
            'telegram_chat_id' => 123,
            'telegram_message_id' => 42,
        ]);
        $request = new Request(Mockery::mock(Client::class), 'GET', new Uri('http://127.0.0.1/files/1/content'));

        $response = (new RoxiTelegramStorageDriver($runtime))->streamDownloadResponse($file, $request);
        $cancellation = $runtime->downloadCancellation;

        $this->assertNotNull($cancellation);
        $this->assertFalse($cancellation->isRequested());
        unset($response);
        EventLoop::run();
        $this->assertTrue($cancellation->isRequested());
    }
}
