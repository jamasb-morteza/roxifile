<?php

namespace App\Domains\Telegram\Data;

final readonly class PersonalTelegramLoginPasswordData
{
    public function __construct(public int $connectionId, public string $password) {}
}
