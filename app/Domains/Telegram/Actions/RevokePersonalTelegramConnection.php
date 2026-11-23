<?php

namespace App\Domains\Telegram\Actions;

use App\Domains\Telegram\Enums\TelegramAccountStatus;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\PersonalTelegramLoginException;
use App\Domains\Telegram\Runtime\PersonalTelegramRuntimeManager;
use Illuminate\Support\Facades\DB;

class RevokePersonalTelegramConnection
{
    public function __construct(private readonly PersonalTelegramRuntimeManager $runtime) {}

    public function handle(int $connectionId, int $accountId): void
    {
        $connection = TelegramConnection::query()
            ->whereKey($connectionId)
            ->where('telegram_account_id', $accountId)
            ->where('method', TelegramMethod::Personal)
            ->first();
        $account = TelegramAccount::query()
            ->whereKey($accountId)
            ->where('method', TelegramMethod::Personal)
            ->where('is_system', false)
            ->first();

        if ($connection === null || $account === null || ($connection->metadata['personal_login_step'] ?? null) !== 'revoking') {
            return;
        }

        try {
            $this->runtime->revoke($account);
        } catch (PersonalTelegramLoginException $exception) {
            if ($exception->retryable) {
                throw $exception;
            }

            $account->forceFill(['credentials' => null, 'status' => TelegramAccountStatus::Error, 'last_error' => 'Telegram could not confirm session revocation. Try again.', 'last_error_at' => now()])->save();
            $connection->forceFill([
                'status' => TelegramConnectionStatus::Error,
                'last_error' => 'Telegram could not confirm session revocation. Try again.',
                'last_error_at' => now(),
                'metadata' => array_merge($connection->metadata ?? [], ['personal_login_step' => 'revoke_failed']),
            ])->save();

            return;
        }

        DB::transaction(function () use ($connection, $account): void {
            TelegramConnection::query()->whereKey($connection->id)->forceDelete();
            TelegramAccount::query()->whereKey($account->id)->forceDelete();
        });
    }

    public function markFailed(int $connectionId, int $accountId): void
    {
        $connection = TelegramConnection::query()
            ->whereKey($connectionId)
            ->where('telegram_account_id', $accountId)
            ->where('method', TelegramMethod::Personal)
            ->first();
        $account = TelegramAccount::query()
            ->whereKey($accountId)
            ->where('method', TelegramMethod::Personal)
            ->first();

        if ($connection === null || $account === null) {
            return;
        }

        $message = 'Telegram could not confirm session revocation. Try again.';
        $account->forceFill(['credentials' => null, 'status' => TelegramAccountStatus::Error, 'last_error' => $message, 'last_error_at' => now()])->save();
        $connection->forceFill([
            'status' => TelegramConnectionStatus::Error,
            'last_error' => $message,
            'last_error_at' => now(),
            'metadata' => array_merge($connection->metadata ?? [], ['personal_login_step' => 'revoke_failed']),
        ])->save();
    }
}
