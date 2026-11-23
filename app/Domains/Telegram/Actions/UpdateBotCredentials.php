<?php

namespace App\Domains\Telegram\Actions;

use App\Domains\Files\Models\File;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Runtime\BotApiException;
use App\Domains\Telegram\Services\TelegramBotApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Replace a bot account's token (spec §32): re-validate against getMe,
 * re-encrypt, and refresh the stored bot identity. Existing file references retain their Bot identity; revoking the old token
 * at Telegram is a separate operator action.
 */
class UpdateBotCredentials
{
    public function handle(TelegramAccount $account, #[\SensitiveParameter] string $token): TelegramAccount
    {
        if ($account->method !== TelegramMethod::Bot) {
            abort(422, 'Credentials can only be updated for bot accounts.');
        }

        try {
            $response = app(TelegramBotApi::class)->call($token, 'getMe');
        } catch (BotApiException $e) {
            abort($e->retryable ? 503 : 422, $e->getMessage());
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            abort(422, 'The bot token was rejected by Telegram. Double-check the token and try again.');
        }

        $bot = $response->json('result') ?? [];

        abort_unless(is_array($bot) && (int) ($bot['id'] ?? 0) > 0, 422, 'Telegram returned an invalid bot identity.');

        return DB::transaction(function () use ($account, $token, $bot): TelegramAccount {
            if ($account->user_id !== null) {
                User::query()->whereKey($account->user_id)->lockForUpdate()->firstOrFail();
            }
            $account = TelegramAccount::query()->lockForUpdate()->findOrFail($account->id);
            $oldId = (int) ($account->metadata['bot_id'] ?? explode(':', $account->credentials ?? '', 2)[0]);
            if ($oldId !== (int) $bot['id'] && File::withTrashed()->where('telegram_account_id', $account->id)->exists()) {
                throw new \InvalidArgumentException('Stored files belong to the original bot. Rotate its token or create a separate connection for a different bot.');
            }
            $account->forceFill([
                'credentials' => $token,
                'metadata' => array_merge($account->metadata ?? [], ['bot_id' => (int) $bot['id'], 'bot_username' => $bot['username'] ?? null]),
            ])->save();
            if ($oldId !== (int) $bot['id']) {
                $account->forceFill(['bot_update_offset' => 0, 'bot_polled_at' => null])->save();
            }
            foreach ($account->connections()->lockForUpdate()->get() as $connection) {
                $connection->forceFill(['metadata' => array_merge($connection->metadata ?? [], ['verify_request_id' => (string) Str::ulid()])]);
                if ($connection->status === TelegramConnectionStatus::Reconnecting) {
                    $connection->forceFill([
                        'status' => TelegramConnectionStatus::Error,
                        'last_error' => 'Credentials changed. Verify this connection again.',
                        'last_error_at' => now(),
                    ]);
                }
                $connection->save();
            }

            return $account;
        });

    }
}
