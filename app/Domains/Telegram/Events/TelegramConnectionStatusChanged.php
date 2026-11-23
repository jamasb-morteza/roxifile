<?php

namespace App\Domains\Telegram\Events;

use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TelegramConnectionStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $connection = 'redis';

    public string $broadcastQueue = 'telegram';

    public readonly TelegramConnection $telegramConnection;

    public readonly string $reason;

    public function __construct(TelegramConnection $connection, string $reason = '')
    {
        $this->telegramConnection = $connection->withoutRelations();
        $this->reason = $reason;
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
            'status' => $this->telegramConnection->status->value,
            'reason' => $this->reason,
        ];
    }

    public function broadcastAs(): string
    {
        return 'connection.status.changed';
    }
}
