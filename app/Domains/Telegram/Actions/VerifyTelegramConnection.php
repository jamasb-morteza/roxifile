<?php

namespace App\Domains\Telegram\Actions;

use App\Domains\Telegram\Enums\ConnectionAttemptResult;
use App\Domains\Telegram\Enums\ConnectionAttemptType;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Events\TelegramConnectionStatusChanged;
use App\Domains\Telegram\Jobs\VerifyTelegramConnectionJob;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Models\TelegramConnectionAttempt;
use App\Domains\Telegram\Runtime\RuntimeNotAvailable;
use App\Domains\Telegram\Runtime\TelegramErrors;
use App\Domains\Telegram\StorageManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Verify a connection through its driver (spec §32): re-resolves the chat,
 * stamps last_verified_at, records the attempt, and flips status on failure.
 */
class VerifyTelegramConnection
{
    public function __construct(
        private readonly StorageManager $storageManager,
    ) {}

    public function queue(TelegramConnection $connection, ?string $chatRef = null): void
    {
        $revision = (string) Str::ulid();
        DB::transaction(function () use ($connection, $chatRef, $revision): void {
            $connection = TelegramConnection::query()->lockForUpdate()->findOrFail($connection->id);
            if ($connection->status === TelegramConnectionStatus::Disabled) {
                throw new \InvalidArgumentException('Enable this connection before verifying it.');
            }
            if (in_array($connection->metadata['chat_setup']['status'] ?? null, ['queued', 'running'], true)) {
                throw new \InvalidArgumentException('Wait for storage chat setup to finish.');
            }
            if ($connection->method !== TelegramMethod::Bot && $connection->chat_id === null) {
                if (($connection->metadata['storage_mode'] ?? null) !== 'existing_chat' || empty($connection->metadata['identity_verified_at'])) {
                    throw new \InvalidArgumentException('Complete the provisioning handshake before verifying this connection.');
                }
                if (empty($connection->metadata['created_chat_id']) && ($chatRef === null || trim($chatRef) === '')) {
                    throw new \InvalidArgumentException('Enter your storage group or channel link.');
                }
            }
            $metadata = array_merge($connection->metadata ?? [], ['verify_request_id' => $revision, 'verify_queued_at' => now()->toIso8601String()]);
            if ($chatRef !== null && ($connection->method === TelegramMethod::Bot || $connection->chat_id === null)) {
                $metadata['chat_ref'] = $chatRef;
            }
            $connection->forceFill(['metadata' => $metadata, 'status' => TelegramConnectionStatus::Reconnecting])->save();
        });
        VerifyTelegramConnectionJob::dispatch($connection->id, $revision);
    }

    public function handle(TelegramConnection $connection, ?string $chatRef = null): TelegramConnection
    {
        if ($chatRef !== null && $chatRef !== '' && ($connection->method === TelegramMethod::Bot || $connection->chat_id === null)) {
            // Manual resolution path (bot connections, phase 13): the user
            // pastes an @username or -100… reference. It is stored as the
            // lookup hint only — the authoritative chat_id comes from the
            // Bot API during driver verification, never from the browser.
            $metadata = $connection->metadata ?? [];
            $metadata['chat_ref'] = $chatRef;
            $connection->forceFill(['metadata' => $metadata])->save();
        }

        $revision = is_string($connection->metadata['verify_request_id'] ?? null) ? $connection->metadata['verify_request_id'] : null;
        $driver = $this->storageManager->for($connection);

        try {
            $verification = $driver->verify($connection);
        } catch (RuntimeNotAvailable $e) {
            // Worker not running: transient — do not flip the connection status.
            throw $e;
        } catch (\Throwable $e) {
            return $this->recordFailure($connection, TelegramErrors::message($e->getMessage()), $revision);
        }

        if (! $verification->ok) {
            return $this->recordFailure($connection, (string) $verification->error, $revision);
        }

        try {
            $connection = DB::transaction(function () use ($connection, $verification, $revision): TelegramConnection {
                TelegramAccount::query()->whereKey($connection->telegram_account_id)->lockForUpdate()->firstOrFail();
                $connection = TelegramConnection::query()->lockForUpdate()->findOrFail($connection->id);
                if ($connection->status === TelegramConnectionStatus::Disabled || ($connection->metadata['verify_request_id'] ?? null) !== $revision) {
                    return $connection;
                }

                if (($connection->metadata['storage_mode'] ?? null) === 'existing_chat' && TelegramConnection::query()
                    ->where('telegram_account_id', $connection->telegram_account_id)
                    ->where(fn ($query) => $query->where('chat_id', $verification->chatId)->orWhere('metadata->created_chat_id', $verification->chatId))
                    ->whereKeyNot($connection->id)
                    ->exists()) {
                    throw new \InvalidArgumentException('This Telegram chat is already connected.');
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

                return $connection;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->recordFailure($connection, 'This Telegram chat already has an active Invite connection.', $revision);
        } catch (\InvalidArgumentException $e) {
            return $this->recordFailure($connection, $e->getMessage(), $revision);
        }
        if ($connection->status === TelegramConnectionStatus::Disabled || ($connection->metadata['verify_request_id'] ?? null) !== $revision) {
            return $connection;
        }

        TelegramConnectionAttempt::create([
            'telegram_connection_id' => $connection->id,
            'telegram_account_id' => $connection->telegram_account_id,
            'type' => ConnectionAttemptType::Verify,
            'result' => ConnectionAttemptResult::Success,
            'metadata' => ['chat_id' => $connection->chat_id],
        ]);

        broadcast(new TelegramConnectionStatusChanged($connection, 'verified'));

        return $connection;
    }

    private function recordFailure(TelegramConnection $connection, string $error, ?string $revision): TelegramConnection
    {
        $connection = DB::transaction(function () use ($connection, $error, $revision): TelegramConnection {
            $connection = TelegramConnection::query()->lockForUpdate()->findOrFail($connection->id);
            if ($connection->status === TelegramConnectionStatus::Disabled || ($connection->metadata['verify_request_id'] ?? null) !== $revision) {
                return $connection;
            }
            $connection->forceFill([
                'status' => TelegramConnectionStatus::Error,
                'last_error' => mb_substr($error, 0, 500),
                'last_error_at' => Carbon::now(),
            ])->save();

            TelegramConnectionAttempt::create([
                'telegram_connection_id' => $connection->id,
                'telegram_account_id' => $connection->telegram_account_id,
                'type' => ConnectionAttemptType::Verify,
                'result' => ConnectionAttemptResult::Failure,
                'error' => mb_substr($error, 0, 500),
            ]);

            return $connection;
        });
        if ($connection->status === TelegramConnectionStatus::Disabled || ($connection->metadata['verify_request_id'] ?? null) !== $revision) {
            return $connection;
        }
        broadcast(new TelegramConnectionStatusChanged($connection, 'verify_failed'));

        return $connection;
    }
}
