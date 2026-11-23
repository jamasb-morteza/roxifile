<?php

namespace App\Domains\Telegram\Runtime;

use Amp\ByteStream\ReadableIterableStream;
use Amp\Pipeline\Queue;
use Throwable;

class TelegramUploadStreamSession
{
    public const CHUNK_BYTES = 65536;

    private const BUFFERED_CHUNKS = 8;

    private readonly Queue $chunks;

    private int $receivedBytes = 0;

    private bool $claimed = false;

    public function __construct(private readonly int $expectedBytes)
    {
        $this->chunks = new Queue(self::BUFFERED_CHUNKS);
    }

    public function write(string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        if (strlen($chunk) > self::CHUNK_BYTES || $this->receivedBytes + strlen($chunk) > $this->expectedBytes) {
            $error = new TelegramUploadStreamException('The upload stream exceeded its declared size.');
            $this->fail($error);

            throw $error;
        }

        $this->receivedBytes += strlen($chunk);
        $this->chunks->push($chunk);
    }

    public function finish(): void
    {
        if ($this->receivedBytes !== $this->expectedBytes) {
            $error = new TelegramUploadStreamException('The upload stream ended before all file bytes arrived.');
            $this->fail($error);

            throw $error;
        }

        $this->chunks->complete();
    }

    public function fail(Throwable $error): void
    {
        if (! $this->chunks->isComplete()) {
            $this->chunks->error($error);
        }
    }

    public function content(): TelegramUploadContent
    {
        if ($this->claimed) {
            throw new TelegramUploadStreamException('The upload stream is already in use.');
        }

        $this->claimed = true;

        return TelegramUploadContent::fromReadableStream(
            new ReadableIterableStream($this->chunks->iterate()),
            $this->expectedBytes,
        );
    }
}
