<?php

namespace App\Domains\Telegram\Data;

final readonly class ConfirmRoxiProvisioningData
{
    public function __construct(
        public string $code,
    ) {}

}
