<?php

namespace App\Domains\Telegram\Data;

final readonly class PersonalTelegramReconnectData
{
    public function __construct(public int $connectionId, public string $phone) {}
}
