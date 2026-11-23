<?php

namespace App\Domains\Telegram\Services;

use App\Domains\Telegram\Enums\ChatType;
use App\Domains\Telegram\Enums\ConnectionAttemptResult;
use App\Domains\Telegram\Enums\ConnectionAttemptType;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Events\TelegramConnectionStatusChanged;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Models\TelegramConnectionAttempt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bot connection provisioning via invite code (spec §6, phase 13).
 *
 * The user opens the bot deep link with the code (or sends `/start <code>`
 * in the target chat when polling is enabled). Codes are random, stored
 * HASHED, single-use, and expire after
 * config('telegram.storage.provision_code_ttl_minutes').
 */
class BotProvisioningService
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Transient attribute carrying the plaintext code to the presenter layer.
     */
    public const TRANSIENT_CODE_ATTRIBUTE = 'provisioning_code';

    /**
     * Issue (or re-issue) an invite code for a pending bot connection.
     */
    public function issueCode(TelegramConnection $connection): string
    {
        if ($connection->status !== TelegramConnectionStatus::Pending) {
            throw new \InvalidArgumentException('Provisioning codes can only be issued for pending connections.');
        }

        if ($connection->method !== TelegramMethod::Bot) {
            throw new \InvalidArgumentException('This service only handles bot connections.');
        }

        $code = sprintf(
            'BOT-%s-%s',
            $this->randomChunk(),
            $this->randomChunk(),
        );

        $connection->forceFill([
            'provision_code_hash' => hash('sha256', $this->normalizeCode($code)),
            'provision_code_expires_at' => Carbon::now()->addMinutes(
                (int) config('telegram.storage.provision_code_ttl_minutes', 15),
            ),
        ])->save();

        return $code;
    }

    /**
     * Handle an incoming `/start <code>` message captured for a bot.
     *
     * @param  int  $chatId  authoritative chat id from the Telegram update
     * @param  array{chat_type: string|null, chat_title: string|null, chat_username: string|null}  $metadata
     */
    public function handleIncomingCode(string $text, int $chatId, array $metadata, ?int $accountId = null, ?int $senderId = null): ?TelegramConnection
    {
        return DB::transaction(fn (): ?TelegramConnection => $this->completeCode($text, $chatId, $metadata, $accountId, $senderId));
    }

    /** @param array{chat_type: string|null, chat_title: string|null, chat_username: string|null} $metadata */
    private function completeCode(string $text, int $chatId, array $metadata, ?int $accountId, ?int $senderId): ?TelegramConnection
    {
        $hash = hash('sha256', $this->normalizeCode($text));

        $connection = TelegramConnection::query()
            ->where('method', TelegramMethod::Bot->value)
            ->where('status', TelegramConnectionStatus::Pending->value)
            ->where('provision_code_hash', $hash)
            ->when($accountId !== null, fn ($query) => $query->where('telegram_account_id', $accountId))
            ->where('provision_code_expires_at', '>', Carbon::now())
            ->lockForUpdate()->first();

        if ($connection === null) {
            return null;
        }

        $connection->forceFill([
            'status' => TelegramConnectionStatus::Connected,
            'chat_id' => $chatId,
            'target_user_id' => $senderId ?? (($metadata['chat_type'] ?? null) === 'private' ? $chatId : null),
            'chat_type' => $this->normalizeChatType($metadata['chat_type'] ?? null),
            'chat_title' => $metadata['chat_title'] ?? null,
            'chat_username' => $metadata['chat_username'] ?? null,
            'last_verified_at' => Carbon::now(),
            'provision_code_hash' => null,
            'provision_code_expires_at' => null,
            'last_error' => null,
            'last_error_at' => null,
        ])->save();

        TelegramConnectionAttempt::create([
            'telegram_connection_id' => $connection->id,
            'telegram_account_id' => $connection->telegram_account_id,
            'type' => ConnectionAttemptType::Provision,
            'result' => ConnectionAttemptResult::Success,
            'metadata' => ['event' => 'code_matched'],
        ]);

        broadcast(new TelegramConnectionStatusChanged($connection, 'provisioned'));

        return $connection;
    }

    /**
     * Deep links the user opens to deliver the code to the bot.
     *
     * @return array{private: string, group: string}
     */
    public function deepLinks(string $botUsername, string $code): array
    {
        $base = 'https://t.me/'.ltrim($botUsername, '@');

        return [
            'private' => $base.'?start='.$code,
            'group' => $base.'?startgroup='.$code,
        ];
    }

    /**
     * Normalize a user/bot-provided code before hashing: uppercase, drop
     * spaces and punctuation.
     */
    public function normalizeCode(string $code): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        if (str_starts_with($normalized, 'BOT')) {
            return $normalized;
        }

        return 'BOT'.$normalized;
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

    /**
     * Map Bot API chat type strings to our ChatType enum.
     */
    private function normalizeChatType(?string $type): ?ChatType
    {
        return match ($type) {
            'private' => ChatType::Private,
            'group' => ChatType::Group,
            'supergroup' => ChatType::Supergroup,
            'channel' => ChatType::Channel,
            default => null,
        };
    }
}
