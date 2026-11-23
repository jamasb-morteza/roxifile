<?php

namespace App\Domains\Telegram\Runtime;

use RuntimeException;
use Throwable;

class PersonalTelegramSessionUnavailable extends RuntimeException
{
    public const USER_MESSAGE = 'The personal Telegram session is unavailable. Reconnect the connection and retry this operation.';

    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(self::USER_MESSAGE, 0, $previous);
    }
}
