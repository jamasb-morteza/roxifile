<?php

namespace App\Domains\Telegram\Actions;

use App\Domains\Telegram\Data\SetupRoxiStorageChatData;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Events\TelegramConnectionStatusChanged;
use App\Domains\Telegram\Jobs\RunRoxiStorageChatSetup;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\TelegramErrors;
use App\Domains\Telegram\Services\CreateRoxiStorageChat;
use App\Domains\Telegram\Services\DiscoverRoxiStorageChats;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SetupRoxiStorageChat
{
    public function queue(TelegramConnection $connection, SetupRoxiStorageChatData $data): void
    {
        $requestId = (string) Str::ulid();
        DB::transaction(function () use ($connection, $data, $requestId): void {
            $connection = TelegramConnection::query()->lockForUpdate()->findOrFail($connection->id);
            if ($connection->method !== TelegramMethod::Invite || ($connection->metadata['storage_mode'] ?? null) !== 'existing_chat'
                || empty($connection->metadata['identity_verified_at']) || $connection->target_user_id === null) {
                throw new \InvalidArgumentException('Verify your Telegram identity before setting up a storage chat.');
            }
            if (! in_array($data->mode, ['discover', 'create'], true)) {
                throw new \InvalidArgumentException('Choose Discover or automatic group creation.');
            }
            if ($connection->chat_id !== null || in_array($connection->status, [TelegramConnectionStatus::Disabled, TelegramConnectionStatus::Reconnecting], true)) {
                throw new \InvalidArgumentException('This connection cannot start storage chat setup right now.');
            }
            if (in_array($connection->metadata['chat_setup']['status'] ?? null, ['queued', 'running'], true)) {
                throw new \InvalidArgumentException('Storage chat setup is already in progress.');
            }
            if ($data->mode === 'discover' && ! empty($connection->metadata['created_chat_id'])) {
                throw new \InvalidArgumentException('Finish joining the group Roxi already created for this connection.');
            }
            $connection->forceFill(['metadata' => array_merge($connection->metadata ?? [], [
                'setup_mode' => $data->mode === 'create' ? 'create' : 'existing',
                'group_name' => $data->mode === 'create' && empty($connection->metadata['created_chat_started_at']) && empty($connection->metadata['created_chat_id'])
                    ? ($data->groupName ?? $connection->metadata['group_name'] ?? 'roxifile-'.$connection->id)
                    : ($connection->metadata['group_name'] ?? null),
                'chat_ref' => null,
                'chat_setup' => ['mode' => $data->mode, 'status' => 'queued', 'request_id' => $requestId, 'chats' => [], 'error' => null],
            ])])->save();
        });
        try {
            RunRoxiStorageChatSetup::dispatch($connection->id, $requestId);
        } catch (Throwable $error) {
            $this->fail($connection->id, $requestId, $error);
            throw $error;
        }
    }

    public function handle(int $connectionId, string $requestId): void
    {
        $connection = TelegramConnection::query()->find($connectionId);
        if ($connection === null || ($connection->metadata['chat_setup']['request_id'] ?? null) !== $requestId
            || ! in_array($connection->metadata['chat_setup']['status'] ?? null, ['queued', 'running'], true)) {
            return;
        }
        if ($connection->status === TelegramConnectionStatus::Disabled || $connection->chat_id !== null) {
            $this->update($connectionId, $requestId, ['status' => 'cancelled']);

            return;
        }
        $this->update($connectionId, $requestId, ['status' => 'running']);
        if (($connection->metadata['chat_setup']['mode'] ?? null) === 'discover') {
            $chats = app(DiscoverRoxiStorageChats::class)->handle($connection);
            $this->update($connectionId, $requestId, ['status' => 'complete', 'chats' => $chats]);
        } else {
            $ready = app(CreateRoxiStorageChat::class)->handle($connection);
            $connection = $this->update($connectionId, $requestId, ['status' => $ready ? 'complete' : 'awaiting_join']);
            if ($ready && $connection !== null && $connection->status !== TelegramConnectionStatus::Disabled) {
                app(VerifyTelegramConnection::class)->queue($connection);
            }
        }
    }

    public function fail(int $connectionId, string $requestId, Throwable $error): void
    {
        $this->update($connectionId, $requestId, ['status' => 'failed', 'error' => TelegramErrors::message($error->getMessage())]);
    }

    /** @param array<string, mixed> $attributes */
    private function update(int $connectionId, string $requestId, array $attributes): ?TelegramConnection
    {
        $connection = DB::transaction(function () use ($connectionId, $requestId, $attributes): ?TelegramConnection {
            $connection = TelegramConnection::query()->lockForUpdate()->find($connectionId);
            if ($connection === null || ($connection->metadata['chat_setup']['request_id'] ?? null) !== $requestId) {
                return null;
            }
            if (($attributes['status'] ?? null) === 'failed' && $connection->chat_id === null && $connection->status === TelegramConnectionStatus::Reconnecting) {
                $connection->status = TelegramConnectionStatus::Pending;
            }
            $connection->forceFill(['metadata' => array_merge($connection->metadata ?? [], [
                'chat_setup' => array_merge($connection->metadata['chat_setup'] ?? [], $attributes),
            ])])->save();

            return $connection;
        });
        if ($connection !== null) {
            broadcast(new TelegramConnectionStatusChanged($connection, 'storage_chat_setup'));
        }

        return $connection;
    }
}
