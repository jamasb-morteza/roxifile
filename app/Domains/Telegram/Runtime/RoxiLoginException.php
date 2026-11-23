<?php

namespace App\Domains\Telegram\Runtime;

use RuntimeException;

class RoxiLoginException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
        public readonly bool $keepAttempt = false,
    ) {
        parent::__construct($message);
    }
}
