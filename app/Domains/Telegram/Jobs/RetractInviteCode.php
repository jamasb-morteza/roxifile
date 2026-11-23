<?php

namespace App\Domains\Telegram\Jobs;

use App\Domains\Telegram\Contracts\TelegramPeerClient;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Worker-side retraction of an expired invite's code DM (ADR-0001): delete
 * Roxi's delivered message, then hard-delete the pending connection row.
 */
#[Connection('redis')]
#[Queue('telegram')]
class RetractInviteCode implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public function __construct(
        public readonly int $connectionId,
    ) {}

    public function handle(TelegramPeerClient $peerClient): void
    {
        $connection = TelegramConnection::query()->find($this->connectionId);

        if (
            $connection === null
            || ($connection->status !== TelegramConnectionStatus::Pending && ! ($connection->metadata['removing'] ?? false))
        ) {
            return;
        }

        $chatId = $connection->metadata['dm_chat_id'] ?? null;
        $messageId = $connection->metadata['dm_message_id'] ?? null;

        if (is_int($chatId) && is_int($messageId)) {
            try {
                $peerClient->deleteSentMessage($chatId, $messageId);
            } catch (Throwable) {
                // Best-effort: the message may already be gone. Always prune
                // the row so the code can never verify.
            }
        }

        $connection->forceDelete();
    }
}
