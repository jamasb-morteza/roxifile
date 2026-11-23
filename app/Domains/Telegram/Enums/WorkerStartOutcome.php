<?php

namespace App\Domains\Telegram\Enums;

enum WorkerStartOutcome: string
{
    case Started = 'started';
    case AlreadyRunning = 'already_running';
    case LoginInProgress = 'login_in_progress';
}
