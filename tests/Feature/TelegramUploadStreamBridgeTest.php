<?php

namespace Tests\Feature;

use App\Domains\Telegram\Runtime\TelegramUploadStreamException;
use App\Domains\Telegram\Runtime\TelegramUploadStreamSession;
use Tests\TestCase;

class TelegramUploadStreamBridgeTest extends TestCase
{
    public function test_stream_buffer_applies_backpressure_after_eight_bounded_chunks(): void
    {
        $chunk = str_repeat('x', TelegramUploadStreamSession::CHUNK_BYTES);
        $expectedBytes = 9 * TelegramUploadStreamSession::CHUNK_BYTES;
        $session = new TelegramUploadStreamSession($expectedBytes);

        $producer = \Amp\async(static function () use ($session, $chunk): void {
            for ($index = 0; $index < 9; $index++) {
                $session->write($chunk);
            }
            $session->finish();
        });

        $this->assertFalse($producer->isComplete(), 'The producer must wait when the bounded buffer is full.');

        $content = $session->content();
        $firstByte = $content->read(1);
        $this->assertSame('x', $firstByte);
        $this->assertTrue($producer->isComplete(), 'Reading one queued chunk must release the blocked producer.');

        $readSizes = [strlen($firstByte)];
        while ($content->bytesRead() < $expectedBytes) {
            $readSizes[] = strlen($content->read(TelegramUploadStreamSession::CHUNK_BYTES));
        }
        $producer->await();

        $this->assertLessThanOrEqual(TelegramUploadStreamSession::CHUNK_BYTES, max($readSizes));
        $this->assertSame($expectedBytes, $content->bytesRead());
        $content->close();
    }

    public function test_session_rejects_chunks_larger_than_the_bridge_bound(): void
    {
        $session = new TelegramUploadStreamSession(TelegramUploadStreamSession::CHUNK_BYTES + 1);

        $this->expectException(TelegramUploadStreamException::class);
        $session->write(str_repeat('x', TelegramUploadStreamSession::CHUNK_BYTES + 1));
    }
}
