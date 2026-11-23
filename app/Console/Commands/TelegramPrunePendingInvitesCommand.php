<?php

namespace App\Console\Commands;

use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Jobs\RetractInviteCode;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Console\Command;

/**
 * Invite TTL sweep (ADR-0001): hard-delete pending invite connections whose
 * provisioning code expired. Rows with a delivered code DM first get a
 * worker job that retracts the message, then delete the row; DM-less rows
 * are deleted immediately.
 */
class TelegramPrunePendingInvitesCommand extends Command
{
    protected $signature = 'telegram:prune-pending-invites';

    protected $description = 'Delete expired pending invite connections and retract their code messages';

    public function handle(): int
    {
        $expired = TelegramConnection::query()
            ->where('method', TelegramMethod::Invite)
            ->where('status', TelegramConnectionStatus::Pending)
            ->whereNotNull('provision_code_expires_at')
            ->where('provision_code_expires_at', '<', now())
            ->get();

        $retracted = 0;
        $deleted = 0;

        foreach ($expired as $connection) {
            $messageId = $connection->metadata['dm_message_id'] ?? null;

            if (is_int($messageId)) {
                RetractInviteCode::dispatch($connection->id);
                $retracted++;
            } else {
                $connection->forceDelete();
                $deleted++;
            }
        }

        $this->info("Pruned {$expired->count()} expired pending invite(s): {$deleted} deleted, {$retracted} queued for retraction.");

        return self::SUCCESS;
    }
}
