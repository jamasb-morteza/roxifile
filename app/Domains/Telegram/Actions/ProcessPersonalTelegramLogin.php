<?php

namespace App\Domains\Telegram\Actions;

use App\Domains\Telegram\Enums\PersonalTelegramLoginOutcome;
use App\Domains\Telegram\Enums\TelegramAccountStatus;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\PersonalTelegramLoginException;
use App\Domains\Telegram\Runtime\PersonalTelegramRuntimeManager;
use JsonException;

class ProcessPersonalTelegramLogin
{
    public function __construct(private readonly PersonalTelegramRuntimeManager $runtime) {}

    public function handle(int $accountId): void
    {
        $account = TelegramAccount::query()
            ->where('method', TelegramMethod::Personal)
            ->where('is_system', false)
            ->find($accountId);
        $connection = $account?->connections()->where('method', TelegramMethod::Personal)->first();

        if ($account === null || $connection === null || $connection->status === TelegramConnectionStatus::Disabled) {
            return;
        }

        try {
            $credentials = json_decode((string) $account->credentials, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->markFailure($connection, $account, 'Could not read the sign-in state. Start again.');

            return;
        }

        if (! is_array($credentials)) {
            return;
        }

        try {
            if (isset($credentials['phone'])) {
                $this->runtime->requestCode($account, (string) $credentials['phone']);
                $this->setStep($connection, $account, 'awaiting_code', TelegramConnectionStatus::Pending);

                return;
            }

            if (isset($credentials['code'])) {
                $outcome = $this->runtime->completeCode($account, (string) $credentials['code']);

                if ($outcome === PersonalTelegramLoginOutcome::PasswordRequired) {
                    $this->setStep($connection, $account, 'awaiting_password', TelegramConnectionStatus::Pending);

                    return;
                }

                $this->markConnected($connection, $account);

                return;
            }

            if (isset($credentials['password'])) {
                $this->runtime->completePassword($account, (string) $credentials['password']);
                $this->markConnected($connection, $account);
            }
        } catch (PersonalTelegramLoginException $exception) {
            if ($exception->retryable) {
                throw $exception;
            }

            $this->handleFailure($connection, $account, $exception->reason);
        }
    }

    public function markFailed(int $accountId): void
    {
        $account = TelegramAccount::query()->where('method', TelegramMethod::Personal)->find($accountId);
        $connection = $account?->connections()->where('method', TelegramMethod::Personal)->first();

        if ($account !== null && $connection !== null) {
            $this->markFailure($connection, $account, 'Telegram could not be reached. Start sign-in again.');
        }
    }

    private function setStep(TelegramConnection $connection, TelegramAccount $account, string $step, TelegramConnectionStatus $status): void
    {
        $account->forceFill([
            'credentials' => null,
            'status' => $status === TelegramConnectionStatus::Pending ? TelegramAccountStatus::Reconnecting : TelegramAccountStatus::Error,
        ])->save();
        $connection->forceFill([
            'status' => $status,
            'last_error' => null,
            'last_error_at' => null,
            'metadata' => array_merge($connection->metadata ?? [], ['personal_login_step' => $step]),
        ])->save();
    }

    private function markConnected(TelegramConnection $connection, TelegramAccount $account): void
    {
        $account->forceFill(['credentials' => null, 'status' => TelegramAccountStatus::Connected, 'last_error' => null, 'last_error_at' => null])->save();
        $connection->forceFill([
            'status' => TelegramConnectionStatus::Connected,
            'last_verified_at' => now(),
            'last_error' => null,
            'last_error_at' => null,
            'metadata' => array_merge($connection->metadata ?? [], ['personal_login_step' => 'connected']),
        ])->save();
    }

    private function handleFailure(TelegramConnection $connection, TelegramAccount $account, string $reason): void
    {
        [$step, $message, $status] = match ($reason) {
            'invalid_code' => ['awaiting_code', 'That Telegram code is incorrect. Try again.', TelegramConnectionStatus::Pending],
            'expired_code' => ['awaiting_phone', 'That Telegram code expired. Request a new code.', TelegramConnectionStatus::Error],
            'invalid_password' => ['awaiting_password', 'That two-step verification password is incorrect. Try again.', TelegramConnectionStatus::Pending],
            'invalid_phone' => ['awaiting_phone', 'Telegram rejected that phone number. Check it and try again.', TelegramConnectionStatus::Error],
            default => ['error', 'Telegram sign-in failed. Start again.', TelegramConnectionStatus::Error],
        };

        $account->forceFill([
            'credentials' => null,
            'status' => $status === TelegramConnectionStatus::Pending ? TelegramAccountStatus::Reconnecting : TelegramAccountStatus::Error,
            'last_error' => $message,
            'last_error_at' => now(),
        ])->save();
        $connection->forceFill([
            'status' => $status,
            'last_error' => $message,
            'last_error_at' => now(),
            'metadata' => array_merge($connection->metadata ?? [], ['personal_login_step' => $step]),
        ])->save();
    }

    private function markFailure(TelegramConnection $connection, TelegramAccount $account, string $message): void
    {
        $account->forceFill(['credentials' => null, 'status' => TelegramAccountStatus::Error, 'last_error' => $message, 'last_error_at' => now()])->save();
        $connection->forceFill([
            'status' => TelegramConnectionStatus::Error,
            'last_error' => $message,
            'last_error_at' => now(),
            'metadata' => array_merge($connection->metadata ?? [], ['personal_login_step' => 'error']),
        ])->save();
    }
}
