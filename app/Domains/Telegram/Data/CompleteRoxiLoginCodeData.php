<?php

namespace App\Domains\Telegram\Data;

readonly class CompleteRoxiLoginCodeData
{
    public function __construct(public string $code) {}
}
