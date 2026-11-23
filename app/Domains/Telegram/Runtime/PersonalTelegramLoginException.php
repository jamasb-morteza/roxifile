<?php

namespace App\Domains\Telegram\Runtime;

use RuntimeException;

class PersonalTelegramLoginException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly bool $retryable = false,
        public readonly int $retryAfterSeconds = 5,
    ) {
        parent::__construct('Personal Telegram sign-in could not be completed.');
    }
}
