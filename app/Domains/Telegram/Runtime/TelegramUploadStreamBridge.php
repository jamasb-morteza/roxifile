<?php

namespace App\Domains\Telegram\Runtime;

class TelegramUploadStreamBridge
{
    /** @var array<int, TelegramUploadStreamSession> */
    private array $sessions = [];

    public function begin(int $operationId, int $expectedBytes): TelegramUploadStreamSession
    {
        if ($operationId < 1 || $expectedBytes < 1 || isset($this->sessions[$operationId])) {
            throw new TelegramUploadStreamException('The upload stream session is invalid or already active.');
        }

        return $this->sessions[$operationId] = new TelegramUploadStreamSession($expectedBytes);
    }

    public function consume(int $operationId): TelegramUploadContent
    {
        $session = $this->sessions[$operationId] ?? null;

        if ($session === null) {
            throw new TelegramUploadStreamException('The upload stream is not available in this worker.');
        }

        return $session->content();
    }

    public function finish(int $operationId): void
    {
        unset($this->sessions[$operationId]);
    }

    public function fail(int $operationId, \Throwable $error): void
    {
        $session = $this->sessions[$operationId] ?? null;
        if ($session !== null) {
            $session->fail($error);
        }

        $this->finish($operationId);
    }
}
