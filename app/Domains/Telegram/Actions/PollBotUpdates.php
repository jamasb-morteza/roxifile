<?php

namespace App\Domains\Telegram\Actions;

use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Services\BotProvisioningService;
use App\Domains\Telegram\Services\TelegramBotApi;
use Illuminate\Support\Facades\DB;

class PollBotUpdates
{
    public function __construct(
        private readonly BotProvisioningService $provisioning,
        private readonly ReceiveTelegramImport $imports,
    ) {}

    public function handle(): void
    {
        $account = TelegramAccount::query()->where('method', 'bot')->whereNotNull('credentials')
            ->whereHas('connections', fn ($query) => $query->where(function ($connections): void {
                $connections->where('status', 'connected')->orWhere(function ($pending): void {
                    $pending->where('status', 'pending')->where('provision_code_expires_at', '>', now());
                });
            }))
            ->orderByRaw('CASE WHEN bot_polled_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('bot_polled_at')->orderBy('id')->first();

        if ($account === null) {
            return;
        }

        $account->forceFill(['bot_polled_at' => now()])->save();
        $response = app(TelegramBotApi::class)->call((string) $account->credentials, 'getUpdates', [
            'offset' => $account->bot_update_offset,
            'limit' => 50,
            'timeout' => 2,
            'allowed_updates' => ['message'],
        ]);

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new \RuntimeException('Bot polling failed. Check the bot token, remove any webhook or competing poller, and make sure Telegram can deliver messages from this chat. Group imports require Telegram to expose the owner messages to the bot.');
        }
        $updates = (array) $response->json('result', []);
        usort($updates, fn (array $left, array $right): int => (int) ($left['update_id'] ?? -1) <=> (int) ($right['update_id'] ?? -1));

        foreach ($updates as $update) {
            DB::transaction(function () use ($account, $update): void {
                $current = TelegramAccount::query()->lockForUpdate()->find($account->id);
                if ($current === null || $current->credentials !== $account->credentials) {
                    return;
                }

                $updateId = (int) ($update['update_id'] ?? -1);
                if ($updateId < $current->bot_update_offset) {
                    return;
                }

                $message = $update['message'] ?? [];
                $chat = $message['chat'] ?? [];
                if (isset($chat['id']) && preg_match('/^\/start(?:@[A-Za-z0-9_]+)?\s+([A-Za-z0-9_-]+)$/', trim($message['text'] ?? ''), $matches) === 1) {
                    $this->provisioning->handleIncomingCode($matches[1], (int) $chat['id'], [
                        'chat_type' => $chat['type'] ?? null,
                        'chat_title' => $chat['title'] ?? $chat['first_name'] ?? null,
                        'chat_username' => $chat['username'] ?? null,
                    ], $current->id, isset($message['from']['id']) ? (int) $message['from']['id'] : null);
                }

                $this->imports->handleBotUpdate($current->id, $message);
                $current->forceFill(['bot_update_offset' => $updateId + 1])->save();
            });
        }
    }
}
