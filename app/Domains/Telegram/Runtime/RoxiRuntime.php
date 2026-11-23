<?php

namespace App\Domains\Telegram\Runtime;

use Amp\Cancellation;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use danog\DialogId\DialogId;
use danog\MadelineProto\MTProto;

/**
 * Process-local handle on the running Roxi MadelineProto client.
 *
 * Bound by the worker's event handler at startup; never instantiated on the
 * HTTP side (Rule 1). Storage drivers resolve this from the container and
 * must use the ALREADY-RUNNING client (Rule 2 / spec §27).
 */
class RoxiRuntime
{
    protected ?MTProto $client = null;

    /**
     * Bind the live client (called from the event handler once the session is up).
     */
    public function bind(MTProto $client): void
    {
        $this->client = $client;
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function methodCallAsyncRead(string $method, array $arguments): array
    {
        return (array) $this->client()->methodCallAsyncRead($method, $arguments);
    }

    /** @return array<string, mixed> */
    public function uploadFromCallable(callable $read, int $size, string $mime, string $name, callable $cb, bool $seekable): array
    {
        return $this->client()->uploadFromCallable($read, $size, $mime, $name, $cb, $seekable);
    }

    /** @param array<string, mixed> $media */
    public function downloadToFile(array $media, string $path): void
    {
        $this->client()->downloadToFile($media, $path);
    }

    /** @param array<string, mixed> $media */
    public function downloadToResponse(
        array $media,
        Request $request,
        int $size,
        string $mime,
        string $name,
        ?Cancellation $cancellation = null,
    ): Response {
        return $this->client()->downloadToResponse($media, $request, null, $size, $mime, $name, $cancellation);
    }

    /** @param list<int> $messageIds
     * @return array<string, mixed>
     */
    public function getMessages(int $chatId, array $messageIds): array
    {
        return DialogId::isSupergroupOrChannel($chatId)
            ? $this->methodCallAsyncRead('channels.getMessages', ['channel' => $chatId, 'id' => $messageIds])
            : $this->methodCallAsyncRead('messages.getMessages', ['id' => $messageIds]);
    }

    /** @param list<int> $messageIds */
    public function deleteMessages(int $chatId, array $messageIds): void
    {
        if (DialogId::isSupergroupOrChannel($chatId)) {
            $this->methodCallAsyncRead('channels.deleteMessages', ['channel' => $chatId, 'id' => $messageIds]);
        } else {
            $this->methodCallAsyncRead('messages.deleteMessages', ['id' => $messageIds, 'revoke' => true]);
        }
    }

    /** @return array<string, mixed> */
    public function getFullInfo(int $chatId): array
    {
        return $this->client()->getFullInfo($chatId);
    }

    /** @return list<int> */
    public function getDialogIds(): array
    {
        return $this->client()->getDialogIds();
    }

    /** @param int|string|array<string, mixed> $peer
     * @return array<string, mixed>
     */
    public function getInfo(int|string|array $peer): array
    {
        return $this->client()->getInfo($peer);
    }

    public function unbind(): void
    {
        $this->client = null;
    }

    public function isRunning(): bool
    {
        return $this->client !== null;
    }

    /**
     * @throws RuntimeNotAvailable when called outside the worker process
     */
    public function client(): MTProto
    {
        if ($this->client === null) {
            throw new RuntimeNotAvailable('Worker runtime not available: no MadelineProto client is running in this process.');
        }

        return $this->client;
    }
}
