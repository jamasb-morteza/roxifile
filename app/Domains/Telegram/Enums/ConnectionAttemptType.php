<?php

namespace App\Domains\Telegram\Enums;

enum ConnectionAttemptType: string
{
    case Provision = 'provision';
    case Verify = 'verify';
    case Reconcile = 'reconcile';
    case Reconnect = 'reconnect';
}
