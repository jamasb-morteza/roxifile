<?php

namespace App\Domains\Telegram\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Data\PersonalTelegramConnectionData;
use App\Domains\Telegram\Data\PersonalTelegramLoginCodeData;
use App\Domains\Telegram\Data\PersonalTelegramLoginPasswordData;
use App\Domains\Telegram\Data\PersonalTelegramReconnectData;
use App\Domains\Telegram\Enums\TelegramAccountStatus;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Jobs\ProcessPersonalTelegramLoginJob;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Support\Facades\DB;

class ManagePersonalTelegramConnection
{
    public function start(User $user, PersonalTelegramConnectionData $data): TelegramConnection
    {
        $connection = DB::transaction(function () use ($user, $data): TelegramConnection {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (TelegramAccount::query()->where('user_id', $user->id)->where('method', TelegramMethod::Personal)->exists()) {
                abort(409, 'A personal Telegram connection already exists. Reconnect or remove it first.');
            }

            $account = TelegramAccount::query()->create([
                'user_id' => $user->id,
                'name' => trim($data->name),
                'method' => TelegramMethod::Personal,
                'is_system' => false,
                'status' => TelegramAccountStatus::Reconnecting,
                'credentials' => $this->credentials('phone', $data->phone),
            ]);

            return TelegramConnection::query()->create([
                'user_id' => $user->id,
                'telegram_account_id' => $account->id,
                'method' => TelegramMethod::Personal,
                'name' => trim($data->name),
                'status' => TelegramConnectionStatus::Connecting,
                'metadata' => ['personal_login_step' => 'requesting_code'],
            ]);
        });

        ProcessPersonalTelegramLoginJob::dispatch($connection->telegram_account_id);

        return $connection;
    }

    public function reconnect(User $user, PersonalTelegramReconnectData $data): TelegramConnection
    {
        $connection = DB::transaction(function () use ($user, $data): TelegramConnection {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $connection = $this->ownedPersonalConnection($user, $data->connectionId, lock: true);

            if (in_array($connection->status, [TelegramConnectionStatus::Connected, TelegramConnectionStatus::Connecting], true)) {
                abort(409, 'This personal Telegram connection is already active or signing in.');
            }

            $account = TelegramAccount::query()->whereKey($connection->telegram_account_id)->lockForUpdate()->firstOrFail();
            $account->forceFill([
                'status' => TelegramAccountStatus::Reconnecting,
                'credentials' => $this->credentials('phone', $data->phone),
            ])->save();
            $connection->forceFill([
                'status' => TelegramConnectionStatus::Connecting,
                'last_error' => null,
                'last_error_at' => null,
                'metadata' => array_merge($connection->metadata ?? [], ['personal_login_step' => 'requesting_code']),
            ])->save();

            return $connection;
        });

        ProcessPersonalTelegramLoginJob::dispatch($connection->telegram_account_id);

        return $connection;
    }

    public function submitCode(User $user, PersonalTelegramLoginCodeData $data): TelegramConnection
    {
        return $this->submitLoginValue($user, $data->connectionId, 'awaiting_code', 'code', $data->code, 'checking_code');
    }

    public function submitPassword(User $user, PersonalTelegramLoginPasswordData $data): TelegramConnection
    {
        return $this->submitLoginValue($user, $data->connectionId, 'awaiting_password', 'password', $data->password, 'checking_password');
    }

    private function submitLoginValue(User $user, int $connectionId, string $expectedStep, string $credentialName, string $value, string $processingStep): TelegramConnection
    {
        $connection = DB::transaction(function () use ($user, $connectionId, $expectedStep, $credentialName, $value, $processingStep): TelegramConnection {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $connection = $this->ownedPersonalConnection($user, $connectionId, lock: true);

            if (($connection->metadata['personal_login_step'] ?? null) !== $expectedStep) {
                abort(409, 'This Telegram sign-in step has expired. Start the sign-in again.');
            }

            $account = TelegramAccount::query()->whereKey($connection->telegram_account_id)->lockForUpdate()->firstOrFail();
            $account->forceFill(['credentials' => $this->credentials($credentialName, $value)])->save();
            $connection->forceFill([
                'status' => TelegramConnectionStatus::Connecting,
                'last_error' => null,
                'last_error_at' => null,
                'metadata' => array_merge($connection->metadata ?? [], ['personal_login_step' => $processingStep]),
            ])->save();

            return $connection;
        });

        ProcessPersonalTelegramLoginJob::dispatch($connection->telegram_account_id);

        return $connection;
    }

    private function ownedPersonalConnection(User $user, int $connectionId, bool $lock = false): TelegramConnection
    {
        $query = TelegramConnection::query()
            ->where('user_id', $user->id)
            ->where('method', TelegramMethod::Personal);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($connectionId);
    }

    /** @return non-empty-string */
    private function credentials(string $key, string $value): string
    {
        return json_encode([$key => $value], JSON_THROW_ON_ERROR);
    }
}
