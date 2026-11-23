<?php

namespace App\Domains\Telegram\Runtime;

use Amp\ByteStream\ReadableStream;
use Closure;

class TelegramUploadContent
{
    public const READ_CHUNK_BYTES = 65536;

    private string $buffer = '';

    private int $bytesRead = 0;

    private bool $closed = false;

    /**
     * @param  Closure(int): (string|null)  $readChunk
     * @param  Closure(): void  $close
     */
    public function __construct(
        private readonly int $size,
        private readonly Closure $readChunk,
        private readonly Closure $close,
    ) {}

    public static function fromPath(string $path, int $size): self
    {
        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new TelegramUploadStreamException('The private upload source is not readable.');
        }

        return new self(
            $size,
            static function (int $length) use ($stream): ?string {
                $chunk = fread($stream, $length);

                if ($chunk === false) {
                    throw new TelegramUploadStreamException('The private upload source could not be read.');
                }

                return $chunk === '' && feof($stream) ? null : $chunk;
            },
            static function () use ($stream): void {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            },
        );
    }

    public static function fromReadableStream(ReadableStream $stream, int $size): self
    {
        return new self(
            $size,
            static function (int $length) use ($stream): ?string {
                return $stream->read();
            },
            static function () use ($stream): void {
                $stream->close();
            },
        );
    }

    public function read(int $length): string
    {
        if ($length < 1) {
            throw new \InvalidArgumentException('Upload reads must request at least one byte.');
        }

        $remaining = $this->size - $this->bytesRead;
        if ($remaining <= 0) {
            return '';
        }

        $wanted = min($length, $remaining);
        while (strlen($this->buffer) < $wanted) {
            $chunk = ($this->readChunk)($wanted - strlen($this->buffer));

            if ($chunk === null || $chunk === '') {
                throw new TelegramUploadStreamException('The upload stream ended before all file bytes arrived.');
            }

            $this->buffer .= $chunk;
        }

        $chunk = substr($this->buffer, 0, $wanted);
        $this->buffer = substr($this->buffer, $wanted);
        $this->bytesRead += strlen($chunk);

        return $chunk;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function bytesRead(): int
    {
        return $this->bytesRead;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        ($this->close)();
    }
}
