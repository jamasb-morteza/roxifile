<?php

namespace App\Domains\Telegram\Jobs;

use App\Domains\Telegram\Actions\VerifyTelegramConnection;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\RuntimeNotAvailable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Queued connection re-verification (spec §43): dispatched in small batches
 * for connections whose `last_verified_at` went stale. Consumed by the
 * telegram:worker runtime.
 */
#[Connection('redis')]
#[Queue('telegram')]
class VerifyTelegramConnectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $connectionId,
        public readonly ?string $verificationRequestId = null,
    ) {}

    public function handle(VerifyTelegramConnection $verify): void
    {
        $connection = TelegramConnection::query()->find($this->connectionId);

        if ($connection === null || $connection->status === TelegramConnectionStatus::Disabled) {
            return;
        }
        if (in_array($connection->metadata['chat_setup']['status'] ?? null, ['queued', 'running'], true)) {
            return;
        }

        if (($connection->metadata['storage_mode'] ?? null) === 'existing_chat' && $connection->chat_id === null
            && (empty($connection->metadata['identity_verified_at']) || (empty($connection->metadata['chat_ref']) && empty($connection->metadata['created_chat_id'])))) {
            return;
        }
        if ($connection->chat_id === null && (! empty($connection->metadata['created_chat_id']) || ($connection->metadata['setup_mode'] ?? null) === 'create')
            && ($connection->metadata['chat_setup']['status'] ?? null) !== 'complete') {
            return;
        }

        if ($this->verificationRequestId !== null && ($connection->metadata['verify_request_id'] ?? null) !== $this->verificationRequestId) {
            return;
        }
        try {
            $verify->handle($connection);
        } catch (RuntimeNotAvailable) {
            // Worker runtime not ready: transient, nothing to record.
        } catch (Throwable) {
            // The action records its own attempt rows for definitive failures.
        }
    }
}
