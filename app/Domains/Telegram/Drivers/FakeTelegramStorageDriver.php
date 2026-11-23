<?php

namespace App\Domains\Telegram\Drivers;

use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Telegram\Contracts\TelegramStorageDriver;
use App\Domains\Telegram\Data\ConnectionVerification;
use App\Domains\Telegram\Data\TelegramUploadResult;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\TelegramUploadContent;

/**
 * Test/dev double: records calls and returns deterministic results.
 * Pre-program failures with $this->throwOnNext = new SomeException.
 */
class FakeTelegramStorageDriver implements TelegramStorageDriver
{
    public ?\Throwable $throwOnNext = null;

    public ?bool $existsResult = true;

    /** @var list<array{method: string, file: int|null, path: string|null, destination: string|null}> */
    public array $calls = [];

    /** @var array<int, string|null> */
    public array $contents = [];

    public int $nextMessageId = 900;

    public ?\Closure $duringUpload = null;

    public function upload(FileOperation $operation, File $file, TelegramUploadContent $content, callable $onProgress): TelegramUploadResult
    {
        $this->record('upload', $file->id, null, null);
        $this->maybeThrow();

        if ($this->duringUpload !== null) {
            $callback = $this->duringUpload;
            $this->duringUpload = null;
            $callback();
        }

        $onProgress(0, $file->size);

        $bytes = '';
        while ($content->bytesRead() < $content->size()) {
            $bytes .= $content->read(TelegramUploadContent::READ_CHUNK_BYTES);
            $onProgress($content->bytesRead(), $content->size());
        }

        $this->contents[++$this->nextMessageId] = $bytes;

        return new TelegramUploadResult(
            telegramMessageId: $this->nextMessageId,
            telegramMediaId: 'fake-media-'.$this->nextMessageId,
        );
    }

    public function download(File $file, string $destinationPath): void
    {
        $this->record('download', $file->id, null, $destinationPath);
        $this->maybeThrow();

        if (array_key_exists($file->telegram_message_id, $this->contents) && $this->contents[$file->telegram_message_id] === null) {
            throw new \RuntimeException('The remote message was deleted.');
        }
        file_put_contents($destinationPath, $this->contents[$file->telegram_message_id] ?? 'fake-telegram-content-'.$file->id);
    }

    public function delete(File $file): void
    {
        $this->record('delete', $file->id, null, null);
        $this->maybeThrow();
        if ($file->telegram_message_id !== null) {
            $this->contents[$file->telegram_message_id] = null;
        }
    }

    public function exists(File $file): ?bool
    {
        $this->record('exists', $file->id, null, null);
        $this->maybeThrow();

        return $this->existsResult;
    }

    public function verify(TelegramConnection $connection): ConnectionVerification
    {
        $this->record('verify', $connection->id, null, null);
        $this->maybeThrow();

        return new ConnectionVerification(
            ok: true,
            chatId: $connection->chat_id,
            chatType: $connection->chat_type,
            chatTitle: $connection->chat_title ?? 'Fake Chat',
            chatUsername: $connection->chat_username,
        );
    }

    private function maybeThrow(): void
    {
        if ($this->throwOnNext !== null) {
            $throwable = $this->throwOnNext;
            $this->throwOnNext = null;

            throw $throwable;
        }
    }

    private function record(string $method, ?int $fileId, ?string $path, ?string $destination): void
    {
        $this->calls[] = [
            'method' => $method,
            'file' => $fileId,
            'path' => $path,
            'destination' => $destination,
        ];
    }
}
