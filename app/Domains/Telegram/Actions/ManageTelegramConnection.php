<?php

namespace App\Domains\Telegram\Actions;

use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Data\UpdateTelegramConnectionData;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Jobs\RetractInviteCode;
use App\Domains\Telegram\Jobs\RevokePersonalTelegramConnectionJob;
use App\Domains\Telegram\Jobs\VerifyTelegramConnectionJob;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Support\Facades\DB;

class ManageTelegramConnection
{
    public function update(TelegramConnection $connection, UpdateTelegramConnectionData $data): TelegramConnection
    {
        DB::transaction(function () use ($connection, $data): void {
            User::query()->whereKey($connection->user_id)->lockForUpdate()->firstOrFail();
            $connection = TelegramConnection::query()->lockForUpdate()->findOrFail($connection->id);
            if ($data->name !== null) {
                $name = trim($data->name);
                if ($name === '') {
                    throw new \InvalidArgumentException('Connection name cannot be empty.');
                }
                $connection->name = $name;
            }
            if ($data->enabled === false && $connection->status !== TelegramConnectionStatus::Disabled) {
                $connection->metadata = array_merge($connection->metadata ?? [], ['status_before_disable' => $connection->status->value]);
                $connection->status = TelegramConnectionStatus::Disabled;
            } elseif ($data->enabled === true && $connection->status === TelegramConnectionStatus::Disabled) {
                $connection->status = $connection->chat_id === null ? TelegramConnectionStatus::Pending : TelegramConnectionStatus::Reconnecting;
            }
            if ($data->syncCaptionTags !== null) {
                $connection->metadata = array_merge($connection->metadata ?? [], ['sync_caption_tags' => $data->syncCaptionTags]);
            }
            $connection->save();
        });
        $connection->refresh();
        if ($connection->status === TelegramConnectionStatus::Reconnecting) {
            VerifyTelegramConnectionJob::dispatch($connection->id);
        }

        return $connection->refresh();
    }

    public function delete(TelegramConnection $connection, bool $deleteFiles = false): bool
    {
        $deleted = DB::transaction(function () use ($connection, $deleteFiles): bool {
            User::query()->whereKey($connection->user_id)->lockForUpdate()->firstOrFail();
            $connection = TelegramConnection::query()->lockForUpdate()->findOrFail($connection->id);
            if (FileOperation::query()->where('telegram_connection_id', $connection->id)->whereNotIn('status', ['completed', 'cancelled', 'failed'])->exists()) {
                throw new \InvalidArgumentException('Finish or cancel active transfers before deleting this connection.');
            }
            $files = File::withTrashed()->where('telegram_connection_id', $connection->id);
            if ($files->exists()) {
                if (! $deleteFiles) {
                    throw new \InvalidArgumentException('This connection still has file records, including Trash. Confirm removal of those records from the RoxiFile database to delete this connection.');
                }
                foreach ($files->cursor() as $file) {
                    $file->forceDelete();
                }
            }
            if ($connection->method === TelegramMethod::Personal) {
                $connection->forceFill([
                    'status' => TelegramConnectionStatus::Disabled,
                    'provision_code_hash' => null,
                    'provision_code_encrypted' => null,
                    'last_error' => null,
                    'last_error_at' => null,
                    'metadata' => array_merge($connection->metadata ?? [], ['personal_login_step' => 'revoking']),
                ])->save();
                $connection->account()->update(['credentials' => null]);

                return false;
            }
            if (! empty($connection->metadata['dm_message_id'])) {
                $connection->forceFill([
                    'status' => TelegramConnectionStatus::Disabled, 'provision_code_hash' => null,
                    'provision_code_encrypted' => null, 'metadata' => array_merge($connection->metadata ?? [], ['removing' => true]),
                ])->save();

                return false;
            }
            $account = $connection->account;
            $connection->forceDelete();
            if ($account->method === TelegramMethod::Bot && ! $account->connections()->withTrashed()->exists() && ! $account->files()->withTrashed()->exists()) {
                $account->forceDelete();
            }

            return true;
        });
        if (! $deleted) {
            if ($connection->method === TelegramMethod::Personal) {
                RevokePersonalTelegramConnectionJob::dispatch($connection->id, $connection->telegram_account_id);
            } else {
                RetractInviteCode::dispatch($connection->id);
            }
        }

        return ! TelegramConnection::query()->whereKey($connection->id)->exists();
    }
}
