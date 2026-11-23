<?php

namespace App\Domains\Telegram\Events;

use App\Domains\Telegram\Models\TelegramAccount;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TelegramAccountStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Worker-emitted events ride the telegram queue (design doc §4.2).
     */
    public string $connection = 'redis';

    public string $broadcastQueue = 'telegram';

    public readonly TelegramAccount $account;

    public function __construct(TelegramAccount $account)
    {
        $this->account = $account->withoutRelations();
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return $this->account->user_id
            ? [new PrivateChannel('App.Models.User.'.$this->account->user_id)]
            : [];
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'account_id' => $this->account->id,
            'method' => $this->account->method->value,
            'status' => $this->account->status->value,
        ];
    }

    public function broadcastAs(): string
    {
        return 'account.status.changed';
    }
}
