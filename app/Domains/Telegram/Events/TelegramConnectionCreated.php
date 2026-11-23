<?php

namespace App\Domains\Telegram\Events;

use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TelegramConnectionCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly TelegramConnection $telegramConnection;

    public function __construct(TelegramConnection $connection)
    {
        $this->telegramConnection = $connection->withoutRelations();
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
            'account_id' => $this->telegramConnection->telegram_account_id,
            'method' => $this->telegramConnection->method->value,
            'name' => $this->telegramConnection->name,
            'status' => $this->telegramConnection->status->value,
        ];
    }

    public function broadcastAs(): string
    {
        return 'connection.created';
    }
}
