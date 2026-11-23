<?php

namespace Tests\Fakes;

use App\Domains\Telegram\Contracts\PersonalTelegramSessionClient;
use App\Domains\Telegram\Data\PersonalTelegramUploadData;
use App\Domains\Telegram\Data\TelegramUploadResult;
use App\Domains\Telegram\Enums\PersonalTelegramLoginOutcome;
use App\Domains\Telegram\Runtime\TelegramUploadContent;
use Throwable;

class FakePersonalTelegramSessionClient implements PersonalTelegramSessionClient
{
    /** @var list<array{method: string, session: string, operation_id: int|null, file_id: int|null, message_id: int|null}> */
    public array $calls = [];

    /** @var array<int, string> */
    public array $messages = [];

    public ?Throwable $throwOnNextStorageCall = null;

    public ?\Closure $duringUpload = null;

    public ?PersonalTelegramUploadData $lastUploadData = null;

    public int $nextMessageId = 45000;

    public function requestCode(string $sessionName, string $phone): void {}

    public function completeCode(string $sessionName, string $code): PersonalTelegramLoginOutcome
    {
        return PersonalTelegramLoginOutcome::Connected;
    }

    public function completePassword(string $sessionName, string $password): void {}

    public function revoke(string $sessionName): void {}

    public function upload(string $sessionName, PersonalTelegramUploadData $data, TelegramUploadContent $content, callable $onProgress): TelegramUploadResult
    {
        $this->lastUploadData = $data;
        $this->record('upload', $sessionName, $data->operationId, $data->fileId);
        $this->maybeThrow();

        if ($this->duringUpload !== null) {
            $duringUpload = $this->duringUpload;
            $this->duringUpload = null;
            $duringUpload();
        }

        $bytes = '';
        while ($content->bytesRead() < $content->size()) {
            $bytes .= $content->read(TelegramUploadContent::READ_CHUNK_BYTES);
            $onProgress($content->bytesRead(), $content->size());
        }

        $messageId = ++$this->nextMessageId;
        $this->messages[$messageId] = $bytes;

        return new TelegramUploadResult($messageId, 'personal-media-'.$messageId);
    }

    public function download(string $sessionName, int $messageId, string $destinationPath): void
    {
        $this->record('download', $sessionName, null, null, $messageId);
        $this->maybeThrow();

        if (! array_key_exists($messageId, $this->messages)) {
            throw new \RuntimeException('The remote message was deleted.');
        }

        file_put_contents($destinationPath, $this->messages[$messageId]);
    }

    public function delete(string $sessionName, int $messageId): void
    {
        $this->record('delete', $sessionName, null, null, $messageId);
        $this->maybeThrow();
        unset($this->messages[$messageId]);
    }

    public function exists(string $sessionName, int $messageId): bool
    {
        $this->record('exists', $sessionName, null, null, $messageId);
        $this->maybeThrow();

        return array_key_exists($messageId, $this->messages);
    }

    public function verify(string $sessionName): void
    {
        $this->record('verify', $sessionName);
        $this->maybeThrow();
    }

    private function maybeThrow(): void
    {
        if ($this->throwOnNextStorageCall !== null) {
            $exception = $this->throwOnNextStorageCall;
            $this->throwOnNextStorageCall = null;

            throw $exception;
        }
    }

    private function record(string $method, string $sessionName, ?int $operationId = null, ?int $fileId = null, ?int $messageId = null): void
    {
        $this->calls[] = [
            'method' => $method,
            'session' => $sessionName,
            'operation_id' => $operationId,
            'file_id' => $fileId,
            'message_id' => $messageId,
        ];
    }
}
