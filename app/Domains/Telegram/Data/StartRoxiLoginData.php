<?php

namespace App\Domains\Telegram\Data;

readonly class StartRoxiLoginData
{
    public function __construct(public string $phone) {}
}
