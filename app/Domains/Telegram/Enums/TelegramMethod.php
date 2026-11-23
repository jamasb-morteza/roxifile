<?php

namespace App\Domains\Telegram\Enums;

enum TelegramMethod: string
{
    case Bot = 'bot';
    case Invite = 'invite';
    case Roxi = 'roxi';
    case Personal = 'personal';

    public function label(): string
    {
        return match ($this) {
            self::Bot => 'Telegram Bot',
            self::Invite => 'Invite Telegram Account',
            self::Roxi => 'Roxi Shared Account',
            self::Personal => 'Personal Telegram Account',
        };
    }
}
