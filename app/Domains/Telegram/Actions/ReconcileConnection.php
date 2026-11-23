<?php

namespace App\Domains\Telegram\Actions;

use App\Domains\Telegram\Enums\ConnectionAttemptResult;
use App\Domains\Telegram\Enums\ConnectionAttemptType;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Models\TelegramConnectionAttempt;
use App\Domains\Telegram\Runtime\TelegramErrors;
use App\Domains\Telegram\StorageManager;
use Illuminate\Support\Carbon;

/**
 * Periodic per-connection sweep (spec §43): re-verifies access through the
 * driver, refreshes last_verified_at, and records an attempt of type
 * `reconcile`. Unlike manual verification it never broadcasts events and
 * never clears chat metadata — it is a background health probe.
 */
class ReconcileConnection
{
    public function __construct(
        private readonly StorageManager $storageManager,
    ) {}

    public function handle(TelegramConnection $connection): TelegramConnection
    {
        try {
            $verification = $this->storageManager->for($connection)->verify($connection);
        } catch (\Throwable $e) {
            // Transient problem (worker down, network): never flip status.
            $this->recordAttempt($connection, ConnectionAttemptResult::Failure, TelegramErrors::message($e->getMessage()));

            return $connection;
        }

        if (! $verification->ok) {
            return $this->recordFailure($connection, (string) $verification->error);
        }

        $connection->forceFill([
            'status' => TelegramConnectionStatus::Connected,
            'chat_id' => $verification->chatId ?? $connection->chat_id,
            'chat_type' => $verification->chatType ?? $connection->chat_type,
            'chat_title' => $verification->chatTitle ?? $connection->chat_title,
            'chat_username' => $verification->chatUsername ?? $connection->chat_username,
            'last_verified_at' => Carbon::now(),
            'last_error' => null,
            'last_error_at' => null,
        ])->save();

        $this->recordAttempt($connection, ConnectionAttemptResult::Success);

        return $connection;
    }

    private function recordFailure(TelegramConnection $connection, string $error): TelegramConnection
    {
        $connection->forceFill([
            'status' => TelegramConnectionStatus::Error,
            'last_error' => mb_substr($error, 0, 500),
            'last_error_at' => Carbon::now(),
        ])->save();

        $this->recordAttempt($connection, ConnectionAttemptResult::Failure, $error);

        return $connection;
    }

    private function recordAttempt(
        TelegramConnection $connection,
        ConnectionAttemptResult $result,
        ?string $error = null,
    ): void {
        TelegramConnectionAttempt::create([
            'telegram_connection_id' => $connection->id,
            'telegram_account_id' => $connection->telegram_account_id,
            'type' => ConnectionAttemptType::Reconcile,
            'result' => $result,
            'error' => $error === null ? null : mb_substr($error, 0, 500),
        ]);
    }
}
