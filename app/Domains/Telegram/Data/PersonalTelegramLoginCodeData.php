<?php

namespace App\Domains\Telegram\Data;

final readonly class PersonalTelegramLoginCodeData
{
    public function __construct(public int $connectionId, public string $code) {}
}
