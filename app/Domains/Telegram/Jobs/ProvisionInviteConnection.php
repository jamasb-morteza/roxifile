<?php

namespace App\Domains\Telegram\Jobs;

use App\Domains\Telegram\Contracts\PeerResolutionFailed;
use App\Domains\Telegram\Contracts\TelegramPeerClient;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\RuntimeNotAvailable;
use App\Domains\Telegram\Services\RoxiProvisioningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Worker-side Invite provisioning op: resolve the designated target account,
 * deliver the provisioning code to it as a direct message from Roxi, and
 * record the delivered message id so the sweep can retract it on expiry.
 * Consumed by the telegram:worker runtime.
 */
#[Connection('redis')]
#[Queue('telegram')]
class ProvisionInviteConnection implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly int $connectionId,
    ) {}

    public function handle(TelegramPeerClient $peerClient, RoxiProvisioningService $provisioning): void
    {
        $connection = TelegramConnection::query()->find($this->connectionId);

        if (
            $connection === null
            || $connection->method !== TelegramMethod::Invite
            || $connection->status !== TelegramConnectionStatus::Pending
        ) {
            return;
        }

        try {
            $targetUserId = $connection->target_user_id;

            if ($targetUserId === null) {
                $peer = $peerClient->resolveTarget((string) $connection->target_identifier);

                if (($connection->metadata['storage_mode'] ?? null) !== 'existing_chat' && $provisioning->targetAlreadyConnected($connection->telegram_account_id, $peer->userId, $connection->id)) {
                    $provisioning->markInviteFailed($connection, 'This Telegram account is already connected.');

                    return;
                }

                $targetUserId = $peer->userId;
                $connection->forceFill(['target_user_id' => $targetUserId])->save();
            }

            $code = (string) $connection->provision_code_encrypted;

            if ($code === '') {
                return;
            }

            $expiresIn = (int) max(0, now()->diffInMinutes($connection->provision_code_expires_at));
            $messageId = $peerClient->sendMessage(
                $targetUserId,
                sprintf(
                    'Your RoxiFile verification code: %s (expires in %d min). Reply with this code here to verify your Telegram identity, then finish setup in RoxiFile.',
                    $code,
                    max($expiresIn, 1),
                ),
            );

            $connection->forceFill([
                'metadata' => array_merge($connection->metadata ?? [], [
                    'dm_chat_id' => $targetUserId,
                    'dm_message_id' => $messageId > 0 ? $messageId : null,
                ]),
            ])->save();
        } catch (PeerResolutionFailed $e) {
            $provisioning->markInviteFailed(
                $connection,
                'That Telegram account could not be reached. Check the @username or mobile number and try again.',
            );
        } catch (RuntimeNotAvailable) {
            // Worker runtime not ready yet: retry shortly.
            $this->release(10);
        } catch (\Throwable) {
            // Transient Telegram failure: let the queue retry strategy handle it.
            $this->release(30);
        }
    }

    /**
     * Permanent delivery failure (retries exhausted, e.g. runtime down):
     * surface it on the connection so the invite does not sit pending forever.
     */
    public function failed(\Throwable $exception): void
    {
        $connection = TelegramConnection::query()->find($this->connectionId);

        if ($connection?->status === TelegramConnectionStatus::Pending) {
            app(RoxiProvisioningService::class)->markInviteFailed(
                $connection,
                'The invite could not be delivered. Please try again.',
            );
        }
    }
}
