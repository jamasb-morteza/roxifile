<?php

namespace App\Domains\Telegram\Runtime;

class BotApiException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable, public readonly ?int $retryAfter = null, public readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }
}
