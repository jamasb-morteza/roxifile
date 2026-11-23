<?php

namespace App\Domains\Telegram\Enums;

enum TelegramConnectionStatus: string
{
    case Pending = 'pending';
    case Connecting = 'connecting';
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case Reconnecting = 'reconnecting';
    case Error = 'error';
    case Disabled = 'disabled';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
