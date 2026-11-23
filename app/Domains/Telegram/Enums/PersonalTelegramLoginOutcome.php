<?php

namespace App\Domains\Telegram\Enums;

enum PersonalTelegramLoginOutcome
{
    case PasswordRequired;
    case Connected;
}
