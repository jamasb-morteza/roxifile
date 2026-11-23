<?php

namespace App\Domains\Telegram\Enums;

enum TelegramAccountStatus: string
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case Error = 'error';
    case Reconnecting = 'reconnecting';
    case Disabled = 'disabled';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
