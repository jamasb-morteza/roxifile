<?php

namespace App\Domains\Telegram\Actions;

use App\Domains\Telegram\Enums\TelegramAccountStatus;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Models\TelegramAccount;

/**
 * Ensure the system-owned Roxi shared Telegram account exists.
 * Idempotent: safe to call from the login command, the worker, and setup.
 */
class EnsureRoxiAccount
{
    public function __invoke(): TelegramAccount
    {
        return TelegramAccount::query()->firstOrCreate(
            [
                'method' => TelegramMethod::Roxi,
                'is_system' => true,
            ],
            [
                'name' => 'Roxi',
                'status' => TelegramAccountStatus::Disconnected,
                'credentials' => null,
            ],
        );
    }
}
