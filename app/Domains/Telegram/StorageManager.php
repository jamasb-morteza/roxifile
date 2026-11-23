<?php

namespace App\Domains\Telegram;

use App\Domains\Telegram\Contracts\TelegramStorageDriver;
use App\Domains\Telegram\Drivers\BotTelegramStorageDriver;
use App\Domains\Telegram\Drivers\PersonalTelegramStorageDriver;
use App\Domains\Telegram\Drivers\RoxiTelegramStorageDriver;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Models\TelegramConnection;

/**
 * Resolves the storage driver for a connection by its method (spec §26).
 */
class StorageManager
{
    public function __construct(
        private readonly RoxiTelegramStorageDriver $roxiDriver,
        private readonly BotTelegramStorageDriver $botDriver,
        private readonly PersonalTelegramStorageDriver $personalDriver,
    ) {}

    public function for(TelegramConnection $connection): TelegramStorageDriver
    {
        return match ($connection->method) {
            // Invite provisioning stores files in Roxi's account like the
            // anonymous handshake — only the handshake differs (ADR-0001).
            TelegramMethod::Roxi, TelegramMethod::Invite => $this->roxiDriver,
            TelegramMethod::Bot => $this->botDriver,
            TelegramMethod::Personal => $this->personalDriver,
        };
    }
}
