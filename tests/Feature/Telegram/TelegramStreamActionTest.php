<?php

namespace Tests\Feature\Telegram;

use App\Domains\Telegram\Actions\SendTelegramUploadStream;
use App\Domains\Telegram\Actions\StreamTelegramFileDownload;
use App\Domains\Telegram\Contracts\TelegramDownloadStreamTransport;
use App\Domains\Telegram\Contracts\TelegramUploadStreamTransport;
use App\Domains\Telegram\Data\SendTelegramUploadStreamData;
use App\Domains\Telegram\Data\StreamTelegramFileDownloadData;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class TelegramStreamActionTest extends TestCase
{
    public function test_upload_stream_action_forwards_typed_transfer_data_to_the_worker_transport(): void
    {
        $data = new SendTelegramUploadStreamData(42, '/tmp/telegram-upload.bin', 128);
        $transport = Mockery::mock(TelegramUploadStreamTransport::class);
        $transport->shouldReceive('send')
            ->once()
            ->with(Mockery::type(SendTelegramUploadStreamData::class));

        (new SendTelegramUploadStream($transport))->handle($data);
    }

    public function test_download_stream_action_forwards_typed_transfer_data_and_returns_worker_response(): void
    {
        $data = new StreamTelegramFileDownloadData(17, 23, false, 'bytes=0-9');
        $response = new Response('streamed bytes', 206);
        $transport = Mockery::mock(TelegramDownloadStreamTransport::class);
        $transport->shouldReceive('stream')
            ->once()
            ->with(Mockery::type(StreamTelegramFileDownloadData::class))
            ->andReturn($response);

        $actual = (new StreamTelegramFileDownload($transport))->handle($data);

        $this->assertSame($response, $actual);
    }
}
