<?php

namespace App\Domains\Telegram\Drivers;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Telegram\Contracts\TelegramDownloadStreamDriver;
use App\Domains\Telegram\Contracts\TelegramStorageDriver;
use App\Domains\Telegram\Data\ConnectionVerification;
use App\Domains\Telegram\Data\TelegramUploadResult;
use App\Domains\Telegram\Enums\ChatType;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\OperationLease;
use App\Domains\Telegram\Runtime\RoxiRuntime;
use App\Domains\Telegram\Runtime\TelegramUploadContent;
use App\Domains\Telegram\Services\TelegramMetadataProjection;
use App\Domains\Telegram\Services\VerifyRoxiStorageChat;
use danog\MadelineProto\RPCErrorException;

/**
 * Roxi driver: uploads/downloads through the ALREADY-RUNNING MadelineProto
 * client bound by the worker's event handler. Never constructs an API
 * instance (spec §27, Rule 1/2).
 */
class RoxiTelegramStorageDriver implements TelegramDownloadStreamDriver, TelegramStorageDriver
{
    public function __construct(private readonly RoxiRuntime $runtime) {}

    public function upload(FileOperation $operation, File $file, TelegramUploadContent $content, callable $onProgress): TelegramUploadResult
    {
        $caption = app(TelegramMetadataProjection::class)->caption($file, TelegramMetadataProjection::ROXI_CAPTION_LIMIT);
        $client = $this->runtime;
        $chatId = $this->requireChatId($file);

        $lastReported = 0;
        $onProgressWrapped = function (float $percent) use ($onProgress, $file, &$lastReported): void {
            $bytes = (int) floor(min(100, max(0, $percent)) / 100 * $file->size);
            $now = time();

            if ($now - $lastReported >= 1 || $percent >= 100.0) {
                $lastReported = $now;
                $onProgress($bytes, $file->size);
            }
        };

        try {
            // The bridge is a sequential bounded stream, so Telegram reads
            // the chunks in order instead of seeking or buffering the file.
            $result = $client->uploadFromCallable(
                static function (int $offset, int $size, ?Cancellation $cancellation = null) use ($content, $operation): string {
                    $cancellation?->throwIfRequested();
                    app(OperationLease::class)->throwIfCancelled($operation->id);

                    return $content->read(max(1, $size));
                },
                $content->size(),
                $file->mime_type,
                $file->name,
                cb: $onProgressWrapped,
                seekable: false,
            );

            // TL methods go through the async call entry point
            // (messages.sendMedia, ... — see InternalDoc).
            app(OperationLease::class)->throwIfCancelled($operation->id);
            $operation = app(OperationLease::class)->beginSend($operation->id);
            $message = $client->methodCallAsyncRead('messages.sendMedia', [
                'peer' => $chatId,
                'media' => ['_' => 'inputMediaUploadedDocument', 'file' => $result, 'mime_type' => $file->mime_type,
                    'attributes' => [['_' => 'documentAttributeFilename', 'file_name' => $file->name]]],
                'random_id' => (int) ($operation->metadata['random_id'] ?? $operation->id),
                'message' => $caption,
                'entities' => [],
            ]);
        } catch (RPCErrorException $e) {
            app(OperationLease::class)->updateMetadata($operation->id, ['send_started_at' => null]);
            throw $e;
        }

        if (($message['_'] ?? '') !== 'updateShortSentMessage') {
            $updates = $message['updates'] ?? [];
            $message = [];
            foreach ($updates as $update) {
                if (in_array($update['_'] ?? '', ['updateNewMessage', 'updateNewChannelMessage'], true)) {
                    $message = $update['message'] ?? [];
                    break;
                }
            }
        }

        $result = new TelegramUploadResult(
            telegramMessageId: (int) ($message['id'] ?? 0),
            telegramMediaId: isset($message['media']) ? (json_encode($message['media']) ?: null) : null,
        );
        app(OperationLease::class)->assertOwned($operation->id);
        $operation = app(OperationLease::class)->updateMetadata($operation->id, [
            'upload_result' => ['message_id' => $result->telegramMessageId, 'media_id' => $result->telegramMediaId, 'file_id' => $result->telegramFileId],
        ]);

        return $result;
    }

    public function download(File $file, string $destinationPath): void
    {
        $client = $this->runtime;
        $chatId = $this->requireChatId($file);

        $message = $this->requireMessage($client, $chatId, (int) $file->telegram_message_id);

        $client->downloadToFile($message['media'], $destinationPath);
    }

    public function streamDownloadResponse(File $file, Request $request): Response
    {
        $client = $this->runtime;
        $chatId = $this->requireChatId($file);
        $message = $this->requireMessage($client, $chatId, (int) $file->telegram_message_id);
        $cancellation = new DeferredCancellation;
        $response = $client->downloadToResponse(
            $message['media'],
            $request,
            $file->size,
            $file->mime_type,
            $file->name,
            $cancellation->getCancellation(),
        );
        $response->onDispose(static fn (): mixed => $cancellation->cancel());

        return $response;
    }

    public function delete(File $file): void
    {
        $client = $this->runtime;
        $chatId = $this->requireChatId($file);

        $client->deleteMessages($chatId, [(int) $file->telegram_message_id]);
    }

    public function exists(File $file): ?bool
    {
        $chatId = $this->requireChatId($file);
        $result = $this->runtime->getMessages($chatId, [(int) $file->telegram_message_id]);

        foreach ($result['messages'] ?? [] as $message) {
            if ((int) ($message['id'] ?? 0) === $file->telegram_message_id && ($message['_'] ?? '') !== 'messageEmpty') {
                return true;
            }
        }

        return false;
    }

    public function verify(TelegramConnection $connection): ConnectionVerification
    {
        if (($connection->metadata['storage_mode'] ?? null) === 'existing_chat') {
            return app(VerifyRoxiStorageChat::class)->handle($connection);
        }

        $client = $this->runtime;

        $info = $client->getFullInfo((int) $connection->chat_id);

        return new ConnectionVerification(
            ok: true,
            chatId: (int) ($info['bot_api_id'] ?? $connection->chat_id),
            chatType: $this->mapType($info['type'] ?? null),
            chatTitle: $info['User']['first_name'] ?? $info['Chat']['title'] ?? null,
            chatUsername: $info['User']['username'] ?? $info['Chat']['username'] ?? null,
        );
    }

    private function requireChatId(File $file): int
    {
        $chatId = $file->telegram_chat_id ?? $file->connection?->chat_id;

        if ($chatId === null) {
            throw new \RuntimeException('File #'.$file->id.' has no Telegram chat mapping.');
        }

        return (int) $chatId;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireMessage(RoxiRuntime $client, int $chatId, int $messageId): array
    {
        $messages = $client->getMessages($chatId, [$messageId]);

        foreach ($messages['messages'] ?? [] as $message) {
            if (($message['id'] ?? null) === $messageId && ($message['_'] ?? '') !== 'messageEmpty') {
                return $message;
            }
        }

        throw new \RuntimeException('Telegram message '.$messageId.' not found in chat '.$chatId.'.');
    }

    private function mapType(?string $type): ?ChatType
    {
        return match ($type) {
            'user', 'bot', 'private' => ChatType::Private,
            'chat', 'group' => ChatType::Group,
            'supergroup' => ChatType::Supergroup,
            'channel' => ChatType::Channel,
            default => null,
        };
    }
}
