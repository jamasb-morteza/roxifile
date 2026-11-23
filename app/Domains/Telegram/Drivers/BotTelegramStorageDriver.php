<?php

namespace App\Domains\Telegram\Drivers;

use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Telegram\Contracts\TelegramStorageDriver;
use App\Domains\Telegram\Data\ConnectionVerification;
use App\Domains\Telegram\Data\TelegramUploadResult;
use App\Domains\Telegram\Enums\ChatType;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\BotApiException;
use App\Domains\Telegram\Runtime\OperationLease;
use App\Domains\Telegram\Runtime\TelegramUploadContent;
use App\Domains\Telegram\Services\TelegramBotApi;
use App\Domains\Telegram\Services\TelegramMetadataProjection;

class BotTelegramStorageDriver implements TelegramStorageDriver
{
    public function __construct(private readonly TelegramBotApi $api) {}

    public function upload(FileOperation $operation, File $file, TelegramUploadContent $content, callable $onProgress): TelegramUploadResult
    {
        $chatId = $file->telegram_chat_id ?? $file->connection?->chat_id;
        if ($chatId === null) {
            throw new \RuntimeException('The storage connection has no verified chat.');
        }
        $caption = app(TelegramMetadataProjection::class)->caption($file, TelegramMetadataProjection::BOT_CAPTION_LIMIT);
        $operation = app(OperationLease::class)->beginSend($operation->id);
        try {
            $response = $this->api->call(
                $this->token($file->account),
                'sendDocument',
                ['chat_id' => $chatId, 'caption' => $caption],
                $content,
                $file->name,
                $onProgress,
            );
        } catch (BotApiException $e) {
            if ($e->httpStatus !== 0) {
                app(OperationLease::class)->updateMetadata($operation->id, ['send_started_at' => null]);
            }
            throw $e;
        }
        $message = $response->json('result', []);
        if (! is_string($message['document']['file_id'] ?? null) || $message['document']['file_id'] === '') {
            app(OperationLease::class)->updateMetadata($operation->id, ['partial_upload_result' => ['message_id' => (int) ($message['message_id'] ?? 0)]]);
            throw new \RuntimeException('Telegram returned incomplete document identifiers. Review this send before retrying.');
        }
        $result = new TelegramUploadResult(
            (int) ($message['message_id'] ?? 0),
            $message['document']['file_unique_id'] ?? null,
            $message['document']['file_id'] ?? null,
        );
        $operation = app(OperationLease::class)->updateMetadata($operation->id, [
            'upload_result' => ['message_id' => $result->telegramMessageId, 'media_id' => $result->telegramMediaId, 'file_id' => $result->telegramFileId],
        ]);
        $onProgress($file->size, $file->size);

        return $result;
    }

    public function download(File $file, string $destinationPath): void
    {
        $response = $this->api->call($this->token($file->account), 'getFile', ['file_id' => $file->telegram_file_id]);
        $path = $response->json('result.file_path');
        if (! is_string($path)) {
            throw new \RuntimeException('Telegram did not return a downloadable file path.');
        }
        $this->api->download($this->token($file->account), $path, $destinationPath);
    }

    public function delete(File $file): void
    {
        try {
            $this->api->call($this->token($file->account), 'deleteMessage', [
                'chat_id' => $file->telegram_chat_id ?? $file->connection?->chat_id,
                'message_id' => $file->telegram_message_id,
            ]);
        } catch (BotApiException $e) {
            if (! str_contains(strtolower($e->getMessage()), 'message to delete not found')) {
                throw $e;
            }
        }
    }

    public function exists(File $file): ?bool
    {
        return null;
    }

    public function verify(TelegramConnection $connection): ConnectionVerification
    {
        $token = $this->token($connection->account);
        $chatRef = $connection->metadata['chat_ref'] ?? $connection->chat_id;
        if ($chatRef === null) {
            return ConnectionVerification::failed('No chat reference provided for this connection.');
        }
        $chat = $this->api->call($token, 'getChat', ['chat_id' => $chatRef])->json('result');
        if (! is_array($chat) || ! isset($chat['id'])) {
            return ConnectionVerification::failed('Chat could not be resolved by the Bot API.');
        }
        $member = $this->api->call($token, 'getChatMember', [
            'chat_id' => $chat['id'], 'user_id' => (int) explode(':', $token, 2)[0],
        ])->json('result', []);
        $type = $chat['type'] ?? '';
        $status = $member['status'] ?? '';
        $canStore = in_array($status, ['creator', 'administrator', 'member', 'restricted'], true);
        if ($type === 'channel') {
            $canStore = $status === 'creator' || ($status === 'administrator' && ($member['can_post_messages'] ?? false) === true);
        } elseif (in_array($type, ['group', 'supergroup'], true) && ! in_array($status, ['creator', 'administrator'], true)) {
            $canStore = $status === 'restricted'
                ? ($member['is_member'] ?? false) === true && ($member['can_send_documents'] ?? false) === true
                : $status === 'member' && ($chat['permissions']['can_send_documents'] ?? false) === true;
        }
        if (! $canStore || ! in_array($type, ['private', 'group', 'supergroup', 'channel'], true)) {
            return ConnectionVerification::failed('The bot does not have permission to store files in this chat.');
        }

        return new ConnectionVerification(
            ok: true, chatId: (int) $chat['id'], chatType: ChatType::tryFrom($chat['type'] ?? ''),
            chatTitle: $chat['title'] ?? $chat['first_name'] ?? null, chatUsername: $chat['username'] ?? null,
        );
    }

    private function token(?TelegramAccount $account): string
    {
        return $account->credentials ?? throw new \RuntimeException('Bot credentials are not configured.');
    }
}
