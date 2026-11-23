<?php

namespace App\Domains\Telegram\Drivers;

use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Telegram\Contracts\TelegramStorageDriver;
use App\Domains\Telegram\Data\ConnectionVerification;
use App\Domains\Telegram\Data\PersonalTelegramUploadData;
use App\Domains\Telegram\Data\TelegramUploadResult;
use App\Domains\Telegram\Enums\ChatType;
use App\Domains\Telegram\Enums\TelegramAccountStatus;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\PersonalTelegramRuntimeManager;
use App\Domains\Telegram\Runtime\PersonalTelegramSessionUnavailable;
use App\Domains\Telegram\Runtime\TelegramErrors;
use App\Domains\Telegram\Runtime\TelegramUploadContent;
use App\Domains\Telegram\Services\TelegramMetadataProjection;
use Throwable;

/**
 * Personal account storage backed by the owner's worker-managed MTProto session.
 */
class PersonalTelegramStorageDriver implements TelegramStorageDriver
{
    public function __construct(
        private readonly PersonalTelegramRuntimeManager $runtime,
        private readonly TelegramMetadataProjection $metadataProjection,
    ) {}

    public function upload(FileOperation $operation, File $file, TelegramUploadContent $content, callable $onProgress): TelegramUploadResult
    {
        if ($operation->user_id !== $file->user_id || $operation->file_id !== $file->id
            || $operation->telegram_connection_id !== $file->telegram_connection_id
            || $operation->telegram_account_id !== $file->telegram_account_id) {
            throw new PersonalTelegramSessionUnavailable;
        }

        $account = $this->accountForFile($file);

        return $this->runtime->upload($account, new PersonalTelegramUploadData(
            operationId: $operation->id,
            fileId: $file->id,
            fileSize: $file->size,
            mimeType: $file->mime_type,
            fileName: $file->name,
            randomId: (int) ($operation->metadata['random_id'] ?? $operation->id),
            caption: $this->metadataProjection->caption($file, TelegramMetadataProjection::ROXI_CAPTION_LIMIT),
        ), $content, $onProgress);
    }

    public function download(File $file, string $destinationPath): void
    {
        $account = $this->accountForFile($file);
        $this->runtime->download($account, $this->messageIdFor($file), $destinationPath);
    }

    public function delete(File $file): void
    {
        $account = $this->accountForFile($file);
        $this->runtime->delete($account, $this->messageIdFor($file));
    }

    public function exists(File $file): ?bool
    {
        $account = $this->accountForFile($file);

        return $this->runtime->exists($account, $this->messageIdFor($file));
    }

    public function verify(TelegramConnection $connection): ConnectionVerification
    {
        try {
            $account = $this->accountForConnection(
                $connection->getKey() === null ? null : (int) $connection->getKey(),
                $connection->user_id,
                $connection->telegram_account_id,
            );
            $this->runtime->verify($account);
        } catch (Throwable $exception) {
            return ConnectionVerification::failed(TelegramErrors::message($exception->getMessage()));
        }

        return new ConnectionVerification(
            ok: true,
            chatId: $connection->chat_id,
            chatType: ChatType::Private,
            chatTitle: $connection->chat_title,
            chatUsername: $connection->chat_username,
        );
    }

    private function accountForFile(File $file): TelegramAccount
    {
        return $this->accountForConnection($file->telegram_connection_id, $file->user_id, $file->telegram_account_id);
    }

    private function accountForConnection(?int $connectionId, int $ownerId, ?int $accountId): TelegramAccount
    {
        if ($connectionId === null || $accountId === null) {
            throw new PersonalTelegramSessionUnavailable;
        }

        $connection = TelegramConnection::query()
            ->whereKey($connectionId)
            ->where('user_id', $ownerId)
            ->where('telegram_account_id', $accountId)
            ->where('method', TelegramMethod::Personal)
            ->where('status', TelegramConnectionStatus::Connected)
            ->first();

        if ($connection === null) {
            throw new PersonalTelegramSessionUnavailable;
        }

        $account = TelegramAccount::query()
            ->whereKey($connection->telegram_account_id)
            ->where('user_id', $ownerId)
            ->where('method', TelegramMethod::Personal)
            ->where('is_system', false)
            ->where('status', TelegramAccountStatus::Connected)
            ->first();

        if ($account === null) {
            throw new PersonalTelegramSessionUnavailable;
        }

        return $account;
    }

    private function messageIdFor(File $file): int
    {
        if ($file->telegram_message_id === null || $file->telegram_message_id < 1) {
            throw new \RuntimeException('The Telegram message for this file is unavailable.');
        }

        return $file->telegram_message_id;
    }
}
