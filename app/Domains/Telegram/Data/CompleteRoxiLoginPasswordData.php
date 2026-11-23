<?php

namespace App\Domains\Telegram\Data;

readonly class CompleteRoxiLoginPasswordData
{
    public function __construct(public string $password) {}
}
