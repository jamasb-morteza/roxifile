<?php

namespace App\Domains\Telegram\Enums;

enum ConnectionAttemptResult: string
{
    case Success = 'success';
    case Failure = 'failure';
}
