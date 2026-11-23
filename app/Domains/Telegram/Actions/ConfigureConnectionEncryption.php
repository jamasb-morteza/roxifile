<?php

namespace App\Domains\Telegram\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Data\ConfigureConnectionEncryptionData;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfigureConnectionEncryption
{
    public function handle(User $user, TelegramConnection $connection, ConfigureConnectionEncryptionData $data): TelegramConnection
    {
        return DB::transaction(function () use ($user, $connection, $data): TelegramConnection {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $connection = TelegramConnection::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($connection->id);
            if ($data->previousEnvelope !== null) {
                $previous = $connection->vault_envelope;
                if ($data->scope !== null && $data->scope !== $connection->encryption_mode) {
                    throw ValidationException::withMessages(['scope' => 'The encryption scope cannot be changed after setup.']);
                }
                if (! in_array($connection->encryption_mode, ['contents', 'contents_and_metadata'], true) || $previous === null || $previous != $data->previousEnvelope) {
                    throw ValidationException::withMessages(['envelope' => 'Vault material changed or is unavailable. Reload this page and unlock again.']);
                }
                if ($data->envelope['recovery'] != $previous['recovery']
                    && ($data->envelope['salt'] !== $previous['salt'] || $data->envelope['password'] != $previous['password'])) {
                    throw ValidationException::withMessages(['envelope' => 'Change only the password or the recovery key at a time.']);
                }
                $connection->update(['vault_envelope' => $data->envelope]);

                return $connection;
            }
            if (! config('telegram.contents_encryption_enabled') || $connection->status !== TelegramConnectionStatus::Connected
                || $connection->encryption_locked_at !== null || $connection->encryption_mode !== 'none'
                || $connection->files()->withTrashed()->exists()) {
                throw ValidationException::withMessages(['envelope' => 'Encryption can only be enabled once, on an empty, connected, unlocked connection.']);
            }
            $connection->update(['encryption_mode' => $data->scope ?? 'contents', 'vault_envelope' => $data->envelope]);

            return $connection;
        });
    }
}
