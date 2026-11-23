<?php

namespace App\Domains\Telegram\Services;

use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\RoxiRuntime;
use App\Domains\Telegram\Runtime\RuntimeNotAvailable;
use Throwable;

class DiscoverRoxiStorageChats
{
    public function __construct(private readonly RoxiRuntime $runtime, private readonly VerifyRoxiStorageChat $verification) {}

    /** @return list<array{id: int, title: string, type: string}> */
    public function handle(TelegramConnection $connection): array
    {
        $reserved = [];
        foreach (TelegramConnection::query()->where('telegram_account_id', $connection->telegram_account_id)->whereKeyNot($connection->id)->get(['chat_id', 'metadata']) as $other) {
            foreach ([$other->chat_id, $other->metadata['created_chat_id'] ?? null] as $id) {
                if ($id !== null) {
                    $reserved[(int) $id] = true;
                }
            }
        }
        $chats = [];
        foreach ($this->runtime->getDialogIds() as $chatId) {
            if ($chatId >= 0 || isset($reserved[$chatId])) {
                continue;
            }
            try {
                $info = $this->verification->inspect($connection, $chatId);
                $chats[] = ['id' => $chatId, 'title' => $info->chatTitle ?? 'Telegram chat', 'type' => $info->chatType->value];
            } catch (RuntimeNotAvailable $error) {
                throw $error;
            } catch (\InvalidArgumentException) {
                continue;
            } catch (Throwable $error) {
                if (! preg_match('/USER_NOT_PARTICIPANT|CHAT_ADMIN_REQUIRED|CHANNEL_PRIVATE|PEER_ID_INVALID|CHAT_ID_INVALID/', $error->getMessage())) {
                    throw $error;
                }
            }
        }
        usort($chats, static fn (array $left, array $right): int => strnatcasecmp($left['title'], $right['title']));

        return $chats;
    }
}
