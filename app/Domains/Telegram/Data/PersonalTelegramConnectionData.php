<?php

namespace App\Domains\Telegram\Data;

final readonly class PersonalTelegramConnectionData
{
    public function __construct(public string $name, public string $phone) {}
}
