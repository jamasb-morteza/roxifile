<?php

namespace App\Domains\Telegram\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Models\TelegramConnection;

class ListStorageConnectionOptions
{
    /**
     * @return array{
     *   connections: list<array{id: int, name: string, method: string, encryption_mode: string, vault_envelope: ?array<string, mixed>}>,
     *   vaults: list<array{id: int, name: string, method: string, encryption_mode: string, vault_envelope: ?array<string, mixed>}>,
     *   filters: list<array{id: int, name: string, chat_title: ?string, avatar_url: ?string}>
     * }
     */
    private function chatAvatarUrl(TelegramConnection $connection): ?string
    {
        // Telegram exposes public group photos via its username. Private groups have no public photo URL.
        if ($connection->chat_username !== null && preg_match('/^[A-Za-z0-9_]{5,32}$/', $connection->chat_username)) {
            return 'https://t.me/i/userpic/320/'.$connection->chat_username.'.jpg';
        }

        return ! empty($connection->metadata['created_chat_photo_set']) ? '/icon.png' : null;
    }

    public function handle(User $user): array
    {
        $connections = TelegramConnection::query()->where('user_id', $user->id)->orderBy('name')->get(['id', 'name', 'method', 'status', 'chat_title', 'chat_username', 'metadata', 'encryption_mode', 'vault_envelope']);

        return [
            'connections' => array_values($connections->where('status', TelegramConnectionStatus::Connected)->map(fn (TelegramConnection $connection): array => [
                'id' => $connection->id, 'name' => $connection->name, 'method' => $connection->method->value,
                'encryption_mode' => $connection->encryption_mode, 'vault_envelope' => $connection->vault_envelope,
            ])->values()->all()),
            'vaults' => array_values($connections->whereIn('encryption_mode', ['contents', 'contents_and_metadata'])->map(fn (TelegramConnection $connection): array => [
                'id' => $connection->id, 'name' => $connection->name, 'method' => $connection->method->value,
                'encryption_mode' => $connection->encryption_mode, 'vault_envelope' => $connection->vault_envelope,
            ])->values()->all()),
            'filters' => array_values($connections->map(fn (TelegramConnection $connection): array => [
                'id' => $connection->id, 'name' => $connection->name, 'chat_title' => $connection->chat_title,
                'avatar_url' => $this->chatAvatarUrl($connection),
            ])->values()->all()),
        ];
    }
}
