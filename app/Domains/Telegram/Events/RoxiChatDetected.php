<?php

namespace App\Domains\Telegram\Events;

use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RoxiChatDetected implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $connection = 'redis';

    public string $broadcastQueue = 'telegram';

    public readonly TelegramConnection $telegramConnection;

    public readonly ?string $chatTitle;

    public function __construct(TelegramConnection $connection, ?string $chatTitle)
    {
        $this->telegramConnection = $connection->withoutRelations();
        $this->chatTitle = $chatTitle;
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
            'chat_title' => $this->chatTitle,
        ];
    }

    public function broadcastAs(): string
    {
        return 'roxi.chat.detected';
    }
}
