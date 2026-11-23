<?php

namespace App\Domains\Telegram\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Data\CreateBotConnectionData;
use App\Domains\Telegram\Enums\ConnectionAttemptResult;
use App\Domains\Telegram\Enums\ConnectionAttemptType;
use App\Domains\Telegram\Enums\TelegramAccountStatus;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Events\TelegramConnectionCreated;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Models\TelegramConnectionAttempt;
use App\Domains\Telegram\Runtime\BotApiException;
use App\Domains\Telegram\Services\BotProvisioningService;
use App\Domains\Telegram\Services\TelegramBotApi;

/**
 * Connect the user's own Telegram Bot (spec §6, phase 13):
 * validate the token via getMe, store it encrypted, create the pending
 * connection, and issue a single-use hashed invite code.
 */
class CreateBotConnection
{
    public function __construct(
        private readonly BotProvisioningService $provisioning,
    ) {}

    /**
     * @return array{connection: TelegramConnection, bot_username: string, deep_links: array{private: string, group: string}, expires_at: string|null}
     */
    public function handle(User $user, CreateBotConnectionData $data): array
    {
        $bot = $this->validateToken($data->token);

        $account = TelegramAccount::query()
            ->where('user_id', $user->id)
            ->where('method', TelegramMethod::Bot)
            ->where('metadata->bot_id', $bot['id'])
            ->first();

        if ($account === null) {
            $account = TelegramAccount::create([
                'user_id' => $user->id,
                'name' => '@'.($bot['username'] ?? 'bot'),
                'method' => TelegramMethod::Bot,
                'is_system' => false,
                'status' => TelegramAccountStatus::Connected,
                'credentials' => $data->token,
                'metadata' => [
                    'bot_id' => $bot['id'],
                    'bot_username' => $bot['username'] ?? null,
                ],
            ]);
        } else {
            // Re-connecting an already known bot: refresh the credentials.
            $account->forceFill(['credentials' => $data->token])->save();
        }

        $connection = TelegramConnection::create([
            'user_id' => $user->id,
            'telegram_account_id' => $account->id,
            'method' => TelegramMethod::Bot,
            'name' => $data->name,
            'status' => TelegramConnectionStatus::Pending,
            'metadata' => ['provisioning' => 'bot_invite_code'],
        ]);

        $code = $this->provisioning->issueCode($connection);

        TelegramConnectionAttempt::create([
            'telegram_connection_id' => $connection->id,
            'telegram_account_id' => $account->id,
            'type' => ConnectionAttemptType::Provision,
            'result' => ConnectionAttemptResult::Success,
            'metadata' => ['event' => 'bot_code_issued'],
        ]);

        broadcast(new TelegramConnectionCreated($connection));

        return [
            'connection' => $connection->setAttribute(BotProvisioningService::TRANSIENT_CODE_ATTRIBUTE, $code),
            'bot_username' => (string) ($bot['username'] ?? ''),
            'deep_links' => $this->provisioning->deepLinks((string) ($bot['username'] ?? ''), $code),
            'expires_at' => $connection->provision_code_expires_at?->toIso8601String(),
        ];
    }

    /**
     * Validate a bot token against the Bot API. Never includes the token in
     * any error message (spec §44).
     *
     * @return array{id: int, username?: string}
     */
    private function validateToken(string $token): array
    {
        try {
            $response = app(TelegramBotApi::class)->call($token, 'getMe');
        } catch (BotApiException $e) {
            abort($e->retryable ? 503 : 422, $e->getMessage());
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            abort(422, 'The bot token was rejected by Telegram. Double-check the token and try again.');
        }

        $result = $response->json('result') ?? [];

        if (! isset($result['id'])) {
            abort(422, 'Telegram returned an unexpected response for this bot token.');
        }

        return [
            'id' => (int) $result['id'],
            'username' => $result['username'] ?? null,
        ];
    }
}
