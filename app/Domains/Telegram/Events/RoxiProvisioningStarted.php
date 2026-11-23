<?php

namespace App\Domains\Telegram\Events;

use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Roxi provisioning started: a hashed code was issued; the user must send it
 * to Roxi on Telegram. Never carries the plaintext code.
 */
class RoxiProvisioningStarted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly TelegramConnection $telegramConnection;

    public readonly string $deepLink;

    public readonly string $expiresAt;

    public function __construct(TelegramConnection $connection, string $deepLink, string $expiresAt)
    {
        $this->telegramConnection = $connection->withoutRelations();
        $this->deepLink = $deepLink;
        $this->expiresAt = $expiresAt;
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->telegramConnection->user_id)];
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'connection_id' => $this->telegramConnection->id,
            'deep_link' => $this->deepLink,
            'expires_at' => $this->expiresAt,
        ];
    }

    public function broadcastAs(): string
    {
        return 'roxi.connection.started';
    }
}
