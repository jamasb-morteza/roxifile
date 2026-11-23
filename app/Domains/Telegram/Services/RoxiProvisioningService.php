<?php

namespace App\Domains\Telegram\Services;

use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Actions\EnsureRoxiAccount;
use App\Domains\Telegram\Actions\SetupRoxiStorageChat;
use App\Domains\Telegram\Contracts\TelegramPeerClient;
use App\Domains\Telegram\Data\SetupRoxiStorageChatData;
use App\Domains\Telegram\Data\StartInviteConnectionData;
use App\Domains\Telegram\Enums\ChatType;
use App\Domains\Telegram\Enums\ConnectionAttemptResult;
use App\Domains\Telegram\Enums\ConnectionAttemptType;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Events\RoxiChatDetected;
use App\Domains\Telegram\Events\RoxiConnectionConnected;
use App\Domains\Telegram\Events\RoxiConnectionFailed;
use App\Domains\Telegram\Events\RoxiProvisioningStarted;
use App\Domains\Telegram\Events\TelegramConnectionStatusChanged;
use App\Domains\Telegram\Jobs\DeliverRoxiConfirmationCode;
use App\Domains\Telegram\Jobs\ProvisionInviteConnection;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Models\TelegramConnectionAttempt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Roxi connection provisioning via the user-initiated code handshake
 * (spec §8, design doc §5).
 *
 * Codes are random, stored hashed, single-use, and expire after their
 * configured TTL. A confirmation code is temporarily encrypted at rest while
 * waiting for worker delivery. Plaintext codes are never stored unencrypted
 * or logged.
 */
class RoxiProvisioningService
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Transient attribute carrying the plaintext code to the presenter layer.
     */
    public const TRANSIENT_CODE_ATTRIBUTE = 'provisioning_code';

    public function __construct(
        private readonly EnsureRoxiAccount $ensureRoxiAccount,
    ) {}

    /**
     * Start provisioning: create a pending roxi connection + issue a code.
     */
    public function start(User $user, string $name): TelegramConnection
    {
        $account = ($this->ensureRoxiAccount)();

        $connection = TelegramConnection::create([
            'user_id' => $user->id,
            'telegram_account_id' => $account->id,
            'method' => TelegramMethod::Roxi,
            'name' => $name,
            'status' => TelegramConnectionStatus::Pending,
            'metadata' => [
                'provisioning' => 'code_handshake',
                'provisioning_state' => 'awaiting_code',
                'provisioning_attempt_id' => (string) Str::ulid(),
            ],
        ]);

        $code = $this->issueCode($connection);

        TelegramConnectionAttempt::create([
            'telegram_connection_id' => $connection->id,
            'telegram_account_id' => $account->id,
            'type' => ConnectionAttemptType::Provision,
            'result' => ConnectionAttemptResult::Success,
            'metadata' => ['event' => 'code_issued'],
        ]);

        broadcast(new RoxiProvisioningStarted(
            $connection,
            $this->deepLink(),
            (string) $connection->provision_code_expires_at?->toIso8601String(),
        ));

        return $connection->setAttribute(self::TRANSIENT_CODE_ATTRIBUTE, $code);
    }

    /**
     * Issue (or re-issue) a provisioning code for a pending connection.
     */
    public function issueCode(TelegramConnection $connection, ?int $ttlMinutes = null): string
    {
        if ($connection->status !== TelegramConnectionStatus::Pending) {
            throw new \InvalidArgumentException('Provisioning codes can only be issued for pending connections.');
        }

        $code = $this->newCode();

        $connection->forceFill([
            'provision_code_hash' => hash('sha256', $this->normalizeCode($code)),
            'provision_code_expires_at' => Carbon::now()->addMinutes(
                $ttlMinutes ?? (int) config('telegram.storage.provision_code_ttl_minutes', 15),
            ),
        ])->save();

        return $code;
    }

    /**
     * Rotate only this pending Roxi connection back to the first handshake step.
     */
    public function regenerateRoxi(TelegramConnection $connection): TelegramConnection
    {
        return DB::transaction(function () use ($connection): TelegramConnection {
            $connection = TelegramConnection::query()->lockForUpdate()->findOrFail($connection->id);

            if ($connection->method !== TelegramMethod::Roxi || $connection->status !== TelegramConnectionStatus::Pending) {
                throw new \InvalidArgumentException('Only pending Roxi connections can be restarted.');
            }

            $code = $this->issueCode($connection);
            $metadata = $connection->metadata ?? [];
            unset(
                $metadata['provisioning_chat_id'],
                $metadata['provisioning_chat_type'],
                $metadata['provisioning_chat_title'],
                $metadata['provisioning_chat_username'],
                $metadata['confirmation_delivered_at'],
                $metadata['confirmation_message_id'],
            );
            $metadata['provisioning_state'] = 'awaiting_code';
            $metadata['provisioning_attempt_id'] = (string) Str::ulid();

            $connection->forceFill([
                'provision_code_encrypted' => null,
                'metadata' => $metadata,
                'last_error' => null,
                'last_error_at' => null,
            ])->save();

            return $connection->setAttribute(self::TRANSIENT_CODE_ATTRIBUTE, $code);
        });
    }

    /**
     * Start Invite provisioning: create an independent pending invite connection
     * designating a target account, then dispatch the worker op that resolves
     * the target and delivers the code to it.
     */
    public function startInvite(User $user, StartInviteConnectionData $data): TelegramConnection
    {
        $account = ($this->ensureRoxiAccount)();
        $target = $this->normalizeTarget($data->target);

        $connection = TelegramConnection::create([
            'user_id' => $user->id,
            'telegram_account_id' => $account->id,
            'method' => TelegramMethod::Invite,
            'name' => $data->name,
            'status' => TelegramConnectionStatus::Pending,
            'target_identifier' => $target,
            'metadata' => ['provisioning' => 'invite', 'storage_mode' => 'existing_chat', 'setup_mode' => $data->setupMode, 'group_name' => $data->groupName],
        ]);

        return $this->reissueInviteCode($connection);
    }

    /**
     * Re-issue an invite connection's code and re-dispatch the delivery op.
     */
    public function regenerateInvite(TelegramConnection $connection): TelegramConnection
    {
        return $this->reissueInviteCode($connection);
    }

    /**
     * Issue an invite code, log the attempt, broadcast, and queue the worker
     * op that resolves the target and DMs the code.
     */
    private function reissueInviteCode(TelegramConnection $connection): TelegramConnection
    {
        if (! empty($connection->metadata['identity_verified_at'])) {
            throw new \InvalidArgumentException('Your Telegram identity is already verified. Connect your storage chat to continue.');
        }
        $code = $this->issueCode(
            $connection,
            (int) config('telegram.storage.invite_code_ttl_minutes', 5),
        );

        // Encrypted-at-rest copy: the worker reads it back to deliver the DM,
        // and verified connections retain it (ADR-0001). Plaintext is never
        // stored unencrypted and never logged.
        $connection->forceFill(['provision_code_encrypted' => $code])->save();

        TelegramConnectionAttempt::create([
            'telegram_connection_id' => $connection->id,
            'telegram_account_id' => $connection->telegram_account_id,
            'type' => ConnectionAttemptType::Provision,
            'result' => ConnectionAttemptResult::Success,
            'metadata' => ['event' => 'invite_code_issued'],
        ]);

        broadcast(new RoxiProvisioningStarted(
            $connection,
            $this->deepLink(),
            (string) $connection->provision_code_expires_at?->toIso8601String(),
        ));

        ProvisionInviteConnection::dispatch($connection->id);

        return $connection->setAttribute(self::TRANSIENT_CODE_ATTRIBUTE, $code);
    }

    /**
     * Normalize a target identifier: lowercase username without "@", or
     * E.164-style phone with a leading "+".
     */
    public function normalizeTarget(string $identifier): string
    {
        $value = trim($identifier);

        if (preg_match('/^\+?[0-9]{6,15}$/', $value) === 1) {
            return str_starts_with($value, '+') ? $value : '+'.$value;
        }

        $username = strtolower(ltrim($value, '@'));

        if (preg_match('/^[a-z0-9_]{4,32}$/', $username) !== 1) {
            throw new \InvalidArgumentException('Invalid target identifier.');
        }

        return $username;
    }

    /**
     * Handle an incoming Telegram message that may be a provisioning code.
     *
     * @param  int  $chatId  sender peer id (authoritative, never from browser)
     * @param  array{chat_id: int, chat_type: string|null, chat_title: string|null, chat_username: string|null}  $metadata
     * @return TelegramConnection|null the matched connection or null when the
     *                                 text does not match an active provisioning code
     */
    public function handleIncomingCode(string $text, int $chatId, array $metadata): ?TelegramConnection
    {
        $hash = hash('sha256', $this->normalizeCode($text));

        // Identity codes belong to individual connections. Legacy DM connections
        // still serialize chat claims on the shared Roxi account.
        return DB::transaction(function () use ($hash, $chatId, $metadata, $text): ?TelegramConnection {
            $connection = TelegramConnection::query()
                ->where('status', TelegramConnectionStatus::Pending->value)
                ->whereIn('method', [TelegramMethod::Roxi->value, TelegramMethod::Invite->value])
                ->where('provision_code_hash', $hash)
                ->where('provision_code_expires_at', '>', Carbon::now())
                ->lockForUpdate()->first();

            if ($connection === null) {
                $expiredRoxi = TelegramConnection::query()
                    ->where('status', TelegramConnectionStatus::Pending->value)
                    ->where('method', TelegramMethod::Roxi->value)
                    ->where('provision_code_hash', $hash)
                    ->where('provision_code_expires_at', '<=', Carbon::now())
                    ->lockForUpdate()->first();

                if ($expiredRoxi !== null && ($expiredRoxi->metadata['provisioning_state'] ?? 'awaiting_code') === 'awaiting_code') {
                    return $this->expireRoxiProvisioning($expiredRoxi);
                }

                return null;
            }

            $isRoxi = $connection->method === TelegramMethod::Roxi;
            $provisioningState = $connection->metadata['provisioning_state'] ?? 'awaiting_code';

            if ($isRoxi) {
                if ($provisioningState !== 'awaiting_code') {
                    return null;
                }

                return $this->beginRoxiConfirmation($connection, $chatId, $metadata);
            }

            // Invite connections only accept the code from the designated target
            // account (ADR-0001); the anonymous Roxi handshake stays open.
            $isInvite = $connection->method === TelegramMethod::Invite;

            if ($isInvite) {
                TelegramAccount::query()->whereKey($connection->telegram_account_id)->lockForUpdate()->firstOrFail();
                if ((int) $connection->target_user_id !== $chatId) {
                    return null;
                }

                $duplicate = ($connection->metadata['storage_mode'] ?? null) === 'existing_chat' ? null : TelegramConnection::query()
                    ->where('method', TelegramMethod::Invite)
                    ->where('telegram_account_id', $connection->telegram_account_id)
                    ->where('status', TelegramConnectionStatus::Connected)
                    ->where('chat_id', $chatId)
                    ->whereKeyNot($connection->id)
                    ->lockForUpdate()
                    ->first();

                if ($duplicate !== null) {
                    return $this->rejectAlreadyConnected($connection);
                }
            }

            $attributes = [
                'status' => TelegramConnectionStatus::Connected,
                'chat_id' => $chatId,
                'chat_type' => $this->normalizeChatType($metadata['chat_type'] ?? null),
                'chat_title' => $metadata['chat_title'] ?? null,
                'chat_username' => $metadata['chat_username'] ?? null,
                'last_verified_at' => Carbon::now(),
                'provision_code_hash' => null,
                'provision_code_expires_at' => null,
                'last_error' => null,
                'last_error_at' => null,
            ];

            if ($isInvite && ($connection->metadata['storage_mode'] ?? null) === 'existing_chat') {
                $connection->forceFill([
                    'metadata' => array_merge($connection->metadata ?? [], ['identity_verified_at' => now()->toIso8601String()]),
                    'provision_code_hash' => null,
                    'provision_code_encrypted' => null,
                    'provision_code_expires_at' => null,
                ])->save();
                TelegramConnectionAttempt::create([
                    'telegram_connection_id' => $connection->id,
                    'telegram_account_id' => $connection->telegram_account_id,
                    'type' => ConnectionAttemptType::Provision,
                    'result' => ConnectionAttemptResult::Success,
                    'metadata' => ['event' => 'identity_verified'],
                ]);
                broadcast(new TelegramConnectionStatusChanged($connection, 'identity_verified'));

                if (($connection->metadata['setup_mode'] ?? null) === 'create') {
                    DB::afterCommit(fn () => app(SetupRoxiStorageChat::class)->queue($connection, new SetupRoxiStorageChatData('create')));
                }

                return $connection;
            }

            // Verified invite connections retain their code, encrypted at rest
            // (ADR-0001); the anonymous handshake keeps clearing all code traces.
            if ($isInvite) {
                $attributes['provision_code_encrypted'] = $this->canonicalCode($text);
            }

            $connection->forceFill($attributes)->save();

            TelegramConnectionAttempt::create([
                'telegram_connection_id' => $connection->id,
                'telegram_account_id' => $connection->telegram_account_id,
                'type' => ConnectionAttemptType::Provision,
                'result' => ConnectionAttemptResult::Success,
                'metadata' => ['event' => 'code_matched', 'chat_type' => $metadata['chat_type'] ?? null],
            ]);

            broadcast(new RoxiChatDetected($connection, $connection->chat_title));
            broadcast(new RoxiConnectionConnected($connection));

            return $connection;
        });
    }

    /**
     * Send the second code from the Telegram worker and record delivery only
     * while the same provisioning attempt is still current.
     */
    public function deliverConfirmationCode(int $connectionId, string $attemptId, TelegramPeerClient $peerClient): void
    {
        /** @var array{chat_id: int, code: string}|null $delivery */
        $delivery = DB::transaction(function () use ($connectionId, $attemptId): ?array {
            $connection = TelegramConnection::query()->lockForUpdate()->find($connectionId);

            if (! $this->isCurrentConfirmationDelivery($connection, $attemptId)) {
                return null;
            }

            if ($connection->provision_code_expires_at === null || $connection->provision_code_expires_at->lessThanOrEqualTo(Carbon::now())) {
                $this->expireRoxiProvisioning($connection);

                return null;
            }

            $chatId = (int) ($connection->metadata['provisioning_chat_id'] ?? 0);
            $code = (string) $connection->provision_code_encrypted;

            if ($chatId <= 0 || $code === '') {
                $this->failRoxiProvisioning($connection, 'The Telegram confirmation could not be prepared. Request a new code.', 'confirmation_delivery_invalid');

                return null;
            }

            return ['chat_id' => $chatId, 'code' => $code];
        });

        if ($delivery === null) {
            return;
        }

        $messageId = $peerClient->sendMessage(
            $delivery['chat_id'],
            sprintf(
                'Your RoxiFile confirmation code: %s. Enter this code in RoxiFile to finish connecting this chat.',
                $delivery['code'],
            ),
        );

        DB::transaction(function () use ($connectionId, $attemptId, $messageId): void {
            $connection = TelegramConnection::query()->lockForUpdate()->find($connectionId);

            if (! $this->isCurrentConfirmationDelivery($connection, $attemptId)) {
                return;
            }

            if ($connection->provision_code_expires_at === null || $connection->provision_code_expires_at->lessThanOrEqualTo(Carbon::now())) {
                $this->expireRoxiProvisioning($connection);

                return;
            }

            $metadata = $connection->metadata ?? [];
            $metadata['provisioning_state'] = 'awaiting_confirmation';
            $metadata['confirmation_delivered_at'] = Carbon::now()->toIso8601String();
            $metadata['confirmation_message_id'] = $messageId > 0 ? $messageId : null;

            $connection->forceFill([
                'metadata' => $metadata,
                'provision_code_encrypted' => null,
            ])->save();
        });
    }

    /**
     * Record a terminal worker failure without changing a newer retry attempt.
     */
    public function failConfirmationDelivery(int $connectionId, string $attemptId): void
    {
        DB::transaction(function () use ($connectionId, $attemptId): void {
            $connection = TelegramConnection::query()->lockForUpdate()->find($connectionId);

            if (! $this->isCurrentConfirmationDelivery($connection, $attemptId)) {
                return;
            }

            if ($connection->provision_code_expires_at === null || $connection->provision_code_expires_at->lessThanOrEqualTo(Carbon::now())) {
                $this->expireRoxiProvisioning($connection);

                return;
            }

            $this->failRoxiProvisioning($connection, 'The confirmation code could not be delivered. Request a new code and try again.', 'confirmation_delivery_failed');
        });
    }

    /**
     * Verify Roxi's second code before committing the Telegram peer as storage.
     */
    public function confirm(TelegramConnection $connection, string $code): TelegramConnection
    {
        /** @var array{connection: TelegramConnection, error: string|null} $result */
        $result = DB::transaction(function () use ($connection, $code): array {
            TelegramAccount::query()->whereKey($connection->telegram_account_id)->lockForUpdate()->firstOrFail();
            $connection = TelegramConnection::query()->lockForUpdate()->findOrFail($connection->id);

            if ($connection->method !== TelegramMethod::Roxi
                || $connection->status !== TelegramConnectionStatus::Pending
                || ($connection->metadata['provisioning_state'] ?? null) !== 'awaiting_confirmation') {
                throw ValidationException::withMessages(['code' => 'This Roxi confirmation is no longer active. Request a new provisioning code.']);
            }

            if ($connection->provision_code_expires_at === null || $connection->provision_code_expires_at->lessThanOrEqualTo(Carbon::now())) {
                $this->expireRoxiProvisioning($connection);

                return ['connection' => $connection, 'error' => 'This Roxi confirmation code has expired. Request a new provisioning code.'];
            }

            $providedHash = hash('sha256', $this->normalizeCode($code));

            if (! is_string($connection->provision_code_hash) || ! hash_equals($connection->provision_code_hash, $providedHash)) {
                throw ValidationException::withMessages(['code' => 'That Roxi confirmation code is incorrect.']);
            }

            $metadata = $connection->metadata ?? [];
            $chatId = (int) ($metadata['provisioning_chat_id'] ?? 0);

            if ($chatId <= 0) {
                $this->failRoxiProvisioning($connection, 'The Telegram chat could not be confirmed. Request a new provisioning code.', 'confirmation_chat_missing');

                return ['connection' => $connection, 'error' => 'The Telegram chat could not be confirmed. Request a new provisioning code.'];
            }

            $duplicate = TelegramConnection::query()
                ->where('telegram_account_id', $connection->telegram_account_id)
                ->where('status', TelegramConnectionStatus::Connected)
                ->where('chat_id', $chatId)
                ->whereKeyNot($connection->id)
                ->exists();

            if ($duplicate) {
                $this->failRoxiProvisioning($connection, 'This Telegram chat is already connected.', 'confirmation_chat_already_connected');

                return ['connection' => $connection, 'error' => 'This Telegram chat is already connected.'];
            }

            $chatType = $this->normalizeChatType($metadata['provisioning_chat_type'] ?? null);
            $connectionMetadata = $metadata;
            unset(
                $connectionMetadata['provisioning_chat_id'],
                $connectionMetadata['provisioning_chat_type'],
                $connectionMetadata['provisioning_chat_title'],
                $connectionMetadata['provisioning_chat_username'],
            );
            $connectionMetadata['provisioning_state'] = 'confirmed';
            $connectionMetadata['confirmed_at'] = Carbon::now()->toIso8601String();

            $connection->forceFill([
                'status' => TelegramConnectionStatus::Connected,
                'chat_id' => $chatId,
                'chat_type' => $chatType,
                'chat_title' => $metadata['provisioning_chat_title'] ?? null,
                'chat_username' => $metadata['provisioning_chat_username'] ?? null,
                'last_verified_at' => Carbon::now(),
                'provision_code_hash' => null,
                'provision_code_expires_at' => null,
                'provision_code_encrypted' => null,
                'last_error' => null,
                'last_error_at' => null,
                'metadata' => $connectionMetadata,
            ])->save();

            TelegramConnectionAttempt::create([
                'telegram_connection_id' => $connection->id,
                'telegram_account_id' => $connection->telegram_account_id,
                'type' => ConnectionAttemptType::Provision,
                'result' => ConnectionAttemptResult::Success,
                'metadata' => ['event' => 'confirmation_code_matched', 'chat_type' => $chatType?->value],
            ]);

            DB::afterCommit(function () use ($connection): void {
                broadcast(new RoxiChatDetected($connection, $connection->chat_title));
                broadcast(new RoxiConnectionConnected($connection));
            });

            return ['connection' => $connection, 'error' => null];
        });

        if ($result['error'] !== null) {
            throw ValidationException::withMessages(['code' => $result['error']]);
        }

        return $result['connection'];
    }

    /**
     * Start the second leg after Roxi receives the browser-issued code.
     *
     * @param  array{chat_id: int, chat_type: string|null, chat_title: string|null, chat_username: string|null}  $peerMetadata
     */
    private function beginRoxiConfirmation(TelegramConnection $connection, int $chatId, array $peerMetadata): TelegramConnection
    {
        $code = $this->newCode();
        $metadata = $connection->metadata ?? [];
        $attemptId = is_string($metadata['provisioning_attempt_id'] ?? null)
            ? $metadata['provisioning_attempt_id']
            : (string) Str::ulid();
        $metadata = array_merge($metadata, [
            'provisioning_state' => 'awaiting_confirmation_delivery',
            'provisioning_attempt_id' => $attemptId,
            'provisioning_chat_id' => $chatId,
            'provisioning_chat_type' => $this->normalizeChatType($peerMetadata['chat_type'] ?? null)?->value,
            'provisioning_chat_title' => $peerMetadata['chat_title'] ?? null,
            'provisioning_chat_username' => $peerMetadata['chat_username'] ?? null,
        ]);

        $connection->forceFill([
            'provision_code_hash' => hash('sha256', $this->normalizeCode($code)),
            'provision_code_expires_at' => Carbon::now()->addMinutes(
                (int) config('telegram.storage.confirmation_code_ttl_minutes', 5),
            ),
            'provision_code_encrypted' => $code,
            'metadata' => $metadata,
        ])->save();

        TelegramConnectionAttempt::create([
            'telegram_connection_id' => $connection->id,
            'telegram_account_id' => $connection->telegram_account_id,
            'type' => ConnectionAttemptType::Provision,
            'result' => ConnectionAttemptResult::Success,
            'metadata' => ['event' => 'confirmation_code_issued'],
        ]);

        DB::afterCommit(fn () => DeliverRoxiConfirmationCode::dispatch($connection->id, $attemptId));

        return $connection;
    }

    private function expireRoxiProvisioning(TelegramConnection $connection): TelegramConnection
    {
        $metadata = $connection->metadata ?? [];
        $metadata['provisioning_state'] = 'expired';
        $metadata = $this->forgetPendingChat($metadata);

        $connection->forceFill([
            'provision_code_hash' => null,
            'provision_code_expires_at' => null,
            'provision_code_encrypted' => null,
            'metadata' => $metadata,
        ])->save();

        return $connection;
    }

    private function failRoxiProvisioning(TelegramConnection $connection, string $message, string $event): TelegramConnection
    {
        $metadata = $connection->metadata ?? [];
        $metadata['provisioning_state'] = 'failed';
        $metadata = $this->forgetPendingChat($metadata);

        $connection->forceFill([
            'provision_code_hash' => null,
            'provision_code_expires_at' => null,
            'provision_code_encrypted' => null,
            'last_error' => $message,
            'last_error_at' => Carbon::now(),
            'metadata' => $metadata,
        ])->save();

        TelegramConnectionAttempt::create([
            'telegram_connection_id' => $connection->id,
            'telegram_account_id' => $connection->telegram_account_id,
            'type' => ConnectionAttemptType::Provision,
            'result' => ConnectionAttemptResult::Failure,
            'error' => $message,
            'metadata' => ['event' => $event],
        ]);

        broadcast(new RoxiConnectionFailed($connection, $message));

        return $connection;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function forgetPendingChat(array $metadata): array
    {
        unset(
            $metadata['provisioning_chat_id'],
            $metadata['provisioning_chat_type'],
            $metadata['provisioning_chat_title'],
            $metadata['provisioning_chat_username'],
            $metadata['confirmation_delivered_at'],
            $metadata['confirmation_message_id'],
        );

        return $metadata;
    }

    private function isCurrentConfirmationDelivery(?TelegramConnection $connection, string $attemptId): bool
    {
        return $connection !== null
            && $connection->method === TelegramMethod::Roxi
            && $connection->status === TelegramConnectionStatus::Pending
            && ($connection->metadata['provisioning_state'] ?? null) === 'awaiting_confirmation_delivery'
            && ($connection->metadata['provisioning_attempt_id'] ?? null) === $attemptId;
    }

    /**
     * Whether the legacy direct-message destination is already connected.
     */
    public function targetAlreadyConnected(int $accountId, int $targetUserId, int $ignoreConnectionId): bool
    {
        return TelegramConnection::query()
            ->where('method', TelegramMethod::Invite)
            ->where('telegram_account_id', $accountId)
            ->where('status', TelegramConnectionStatus::Connected)
            ->where('chat_id', $targetUserId)
            ->whereKeyNot($ignoreConnectionId)
            ->exists();
    }

    /**
     * A duplicate invite verification: park the duplicate in Error, log the
     * attempt, broadcast, and hand the connection back so the worker can tell
     * the sender in Telegram.
     */
    private function rejectAlreadyConnected(TelegramConnection $connection): TelegramConnection
    {
        $connection->forceFill([
            'status' => TelegramConnectionStatus::Error,
            'last_error' => 'This Telegram account is already connected.',
            'last_error_at' => Carbon::now(),
        ])->save();

        TelegramConnectionAttempt::create([
            'telegram_connection_id' => $connection->id,
            'telegram_account_id' => $connection->telegram_account_id,
            'type' => ConnectionAttemptType::Provision,
            'result' => ConnectionAttemptResult::Failure,
            'metadata' => ['event' => 'invite_already_connected'],
        ]);

        broadcast(new RoxiConnectionFailed($connection, 'This Telegram account is already connected.'));

        return $connection;
    }

    /**
     * Park an invite connection in Error after a definitive provisioning
     * failure (unresolvable target, duplicate target).
     */
    public function markInviteFailed(TelegramConnection $connection, string $message): void
    {
        $connection->forceFill([
            'status' => TelegramConnectionStatus::Error,
            'last_error' => $message,
            'last_error_at' => Carbon::now(),
        ])->save();

        TelegramConnectionAttempt::create([
            'telegram_connection_id' => $connection->id,
            'telegram_account_id' => $connection->telegram_account_id,
            'type' => ConnectionAttemptType::Provision,
            'result' => ConnectionAttemptResult::Failure,
            'metadata' => ['event' => 'invite_failed', 'error' => $message],
        ]);

        broadcast(new RoxiConnectionFailed($connection, $message));
    }

    /**
     * The t.me deep link the user opens to reach Roxi on Telegram.
     */
    public function deepLink(): string
    {
        $username = (string) config('telegram.roxi.username');

        return 'https://t.me/'.ltrim($username, '@');
    }

    /**
     * Normalize a user-provided code before hashing: uppercase, drop spaces
     * and punctuation.
     */
    private function normalizeCode(string $code): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        if (str_starts_with($normalized, 'ROXI')) {
            return $normalized;
        }

        return 'ROXI'.$normalized;
    }

    /**
     * Canonical presentation form of a code: ROXI-XXXX-XXXX (for the
     * encrypted-at-retention copy).
     */
    private function canonicalCode(string $code): string
    {
        $normalized = $this->normalizeCode($code);

        return sprintf('%s-%s-%s', substr($normalized, 0, 4), substr($normalized, 4, 4), substr($normalized, 8, 4));
    }

    private function randomChunk(): string
    {
        $length = strlen(self::CODE_ALPHABET);
        $chunk = '';

        for ($i = 0; $i < 4; $i++) {
            $chunk .= self::CODE_ALPHABET[random_int(0, $length - 1)];
        }

        return $chunk;
    }

    private function newCode(): string
    {
        return sprintf('ROXI-%s-%s', $this->randomChunk(), $this->randomChunk());
    }

    /**
     * Map MadelineProto peer type strings to our ChatType enum.
     */
    private function normalizeChatType(?string $type): ?ChatType
    {
        return match ($type) {
            'user', 'bot', 'private' => ChatType::Private,
            'chat', 'group' => ChatType::Group,
            'supergroup' => ChatType::Supergroup,
            'channel' => ChatType::Channel,
            default => null,
        };
    }
}
