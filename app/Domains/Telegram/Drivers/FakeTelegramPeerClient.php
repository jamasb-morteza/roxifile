<?php

namespace App\Domains\Telegram\Drivers;

use App\Domains\Telegram\Contracts\TelegramPeerClient;
use App\Domains\Telegram\Data\ResolvedPeer;

/**
 * Test/dev double: records calls and returns deterministic results.
 * Pre-program failures with $this->throwOnResolve / $this->throwOnSend.
 */
class FakeTelegramPeerClient implements TelegramPeerClient
{
    public ?\Throwable $throwOnResolve = null;

    public ?\Throwable $throwOnSend = null;

    public ResolvedPeer $resolveResult;

    public int $nextMessageId = 400;

    /** @var list<array{method: string, identifier?: string, chat_id?: int, text?: string, message_id?: int}> */
    public array $calls = [];

    public function __construct(?ResolvedPeer $resolveResult = null)
    {
        $this->resolveResult = $resolveResult ?? new ResolvedPeer(
            userId: 555_000,
            username: 'target_user',
            title: 'Target User',
        );
    }

    public function resolveTarget(string $identifier): ResolvedPeer
    {
        $this->calls[] = ['method' => 'resolveTarget', 'identifier' => $identifier];

        if ($this->throwOnResolve !== null) {
            $throwable = $this->throwOnResolve;
            $this->throwOnResolve = null;

            throw $throwable;
        }

        return $this->resolveResult;
    }

    public function sendMessage(int $chatId, string $text): int
    {
        $this->calls[] = ['method' => 'sendMessage', 'chat_id' => $chatId, 'text' => $text];

        if ($this->throwOnSend !== null) {
            $throwable = $this->throwOnSend;
            $this->throwOnSend = null;

            throw $throwable;
        }

        return ++$this->nextMessageId;
    }

    public function deleteSentMessage(int $chatId, int $messageId): void
    {
        $this->calls[] = ['method' => 'deleteSentMessage', 'chat_id' => $chatId, 'message_id' => $messageId];
    }
}
