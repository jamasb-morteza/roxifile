<?php

namespace App\Domains\Telegram\Enums;

enum RoxiLoginStep: string
{
    case AwaitingPhone = 'awaiting_phone';
    case AwaitingCode = 'awaiting_code';
    case AwaitingPassword = 'awaiting_password';
    case Connected = 'connected';
}
