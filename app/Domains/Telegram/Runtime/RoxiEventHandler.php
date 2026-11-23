<?php

declare(strict_types=1);

namespace App\Domains\Telegram\Runtime;

use Amp\SignalException;
use App\Domains\Telegram\Actions\PollBotUpdates;
use App\Domains\Telegram\Actions\ReceiveTelegramImport;
use App\Domains\Telegram\Enums\TelegramAccountStatus;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Services\RoxiProvisioningService;
use danog\MadelineProto\EventHandler\Attributes\Cron;
use danog\MadelineProto\EventHandler\Attributes\Handler;
use danog\MadelineProto\EventHandler\Filter\FilterPrivate;
use danog\MadelineProto\EventHandler\Message;
use danog\MadelineProto\EventHandler\Message\PrivateMessage;
use danog\MadelineProto\MTProto;
use danog\MadelineProto\SimpleEventHandler;
use Revolt\EventLoop;
use Throwable;

/**
 * MadelineProto event handler for the single Roxi runtime.
 *
 * Responsibilities (all inside the long-running worker process):
 *  - binds the live MTProto client into RoxiRuntime for storage drivers;
 *  - pops the `telegram` queue on a fast loop and fires jobs in-process;
 *  - watches private messages for Roxi provisioning codes (Phase 6);
 *  - heartbeats worker health + refreshes the runtime lock.
 */
class RoxiEventHandler extends SimpleEventHandler
{
    /**
     * Called by the artisan command once the loop starts.
     */
    public static ?WorkerHealth $health = null;

    public static ?WorkerLock $lock = null;

    public static bool $stopRequested = false;

    public function onStart(): void
    {
        if (self::$lock === null || ! self::$lock->refresh()) {
            throw new SignalException('Runtime ownership lost during session startup.');
        }
        $previous = EventLoop::getErrorHandler();
        EventLoop::setErrorHandler(static function (Throwable $error) use ($previous): void {
            if ($error instanceof SignalException && in_array($error->getMessage(), ['SIGTERM received', 'SIGINT received'], true)) {
                self::$stopRequested = true;
                app(TelegramQueueConsumer::class)->stop();

                return;
            }
            if ($previous !== null) {
                $previous($error);

                return;
            }
            throw $error;
        });
        app(RoxiRuntime::class)->bind($this->client());

        self::$health?->heartbeat(roxiConnected: true, sessionLoaded: true);

        $this->syncRoxiAccountStatus(TelegramAccountStatus::Connected);
    }

    /**
     * The MTProto client behind this handler (spec Rule 1: one shared runtime).
     */
    private function client(): MTProto
    {
        $api = $this->wrapper->getAPI();

        if ($api instanceof MTProto) {
            return $api;
        }

        throw new \RuntimeException('The MadelineProto client is not available.');
    }

    #[Cron(period: 0.5)]
    public function popTelegramQueue(): void
    {
        $consumer = app(TelegramQueueConsumer::class);
        if (self::$stopRequested) {
            $consumer->stop();
            if ($consumer->shouldExit()) {
                $this->stop();
            }

            return;
        }
        $consumer->tick();
    }

    #[Cron(period: 3)]
    public function pollBotUpdates(): void
    {
        if (! self::$stopRequested) {
            try {
                app(PollBotUpdates::class)->handle();
            } catch (Throwable $e) {
                self::$health?->recordError($e);
            }
        }
    }

    /** @param array<string, mixed> $update */
    public function onUpdateNewMessage(array $update): void
    {
        $this->archiveIfRoxiWasAdded($update['message'] ?? []);
        try {
            app(ReceiveTelegramImport::class)->handle($update['message'] ?? []);
        } catch (Throwable $error) {
            self::$health?->recordError($error);
        }
    }

    /** @param array<string, mixed> $update */
    public function onUpdateNewChannelMessage(array $update): void
    {
        $this->onUpdateNewMessage($update);
    }

    /** @param array<string, mixed> $message */
    private function archiveIfRoxiWasAdded(array $message): void
    {
        $action = $message['action'] ?? [];
        $actionType = $action['_'] ?? '';
        if (! in_array($actionType, ['messageActionChatAddUser', 'messageActionChatJoinedByLink'], true)) {
            return;
        }

        try {
            $client = $this->client();
            $roxiId = (int) ($client->getInfo('me')['bot_api_id'] ?? 0);
            $addedUserIds = array_map('intval', $action['users'] ?? []);
            $joinedByRoxi = $actionType === 'messageActionChatJoinedByLink'
                && $client->getId($message['from_id'] ?? 0) === $roxiId;
            if (! $joinedByRoxi && ! in_array($roxiId, $addedUserIds, true)) {
                return;
            }

            $peerId = $client->getId($message['peer_id'] ?? null);
            $client->methodCallAsyncRead('folders.editPeerFolders', [
                'folder_peers' => [['_' => 'inputFolderPeer', 'peer' => $peerId, 'folder_id' => 1]],
            ]);
        } catch (Throwable $error) {
            self::$health?->recordError($error);
        }
    }

    #[Cron(period: 60)]
    public function recoverTelegramImports(): void
    {
        if (! self::$stopRequested) {
            app(ReceiveTelegramImport::class)->recover();
        }
    }

    #[Handler]
    #[FilterPrivate]
    public function onPrivateMessage(PrivateMessage $message): void
    {
        $text = trim($message->message ?? '');

        if ($text === '' || str_starts_with($text, '/')) {
            return;
        }

        try {
            $connection = app(RoxiProvisioningService::class)->handleIncomingCode(
                $text,
                $message->chatId,
                $this->getPeerMetadata($message),
            );

            if ($connection === null) {
                return;
            }

            if ($connection->status === TelegramConnectionStatus::Pending
                && $connection->method === TelegramMethod::Roxi
                && ($connection->metadata['provisioning_state'] ?? null) === 'expired') {
                $message->reply('This RoxiFile code has expired. Request a new code in RoxiFile and send it here.');
            } elseif ($connection->status === TelegramConnectionStatus::Pending
                && $connection->method === TelegramMethod::Roxi
                && ($connection->metadata['provisioning_state'] ?? null) === 'awaiting_confirmation_delivery') {
                // The worker sends the second code through the Telegram queue.
            } elseif ($connection->status === TelegramConnectionStatus::Pending && ! empty($connection->metadata['identity_verified_at'])) {
                $message->reply('Telegram identity verified. Return to RoxiFile to connect your storage group or channel.');
            } elseif ($connection->status === TelegramConnectionStatus::Connected) {
                $this->replyConfirmation($message, $connection);
            } else {
                // Duplicate invite verification (ADR-0001): tell the sender
                // this account is already connected.
                $this->replyAlreadyConnected($message);
            }
        } catch (Throwable $e) {
            self::$health?->recordError($e);
        }
    }

    /**
     * Reply in Telegram that the account is already connected.
     */
    protected function replyAlreadyConnected(PrivateMessage $message): void
    {
        try {
            $message->reply('This Telegram account is already connected to RoxiFile.');
        } catch (Throwable $e) {
            self::$health?->recordError($e);
        }
    }

    /**
     * Reply in Telegram with a provisioning confirmation.
     */
    protected function replyConfirmation(PrivateMessage $message, mixed $connection): void
    {
        try {
            $message->reply('Storage connection confirmed. You can now upload files from Roxivia.');
        } catch (Throwable $e) {
            self::$health?->recordError($e);
        }
    }

    /**
     * Capture sender/chat metadata for the connection record.
     *
     * @return array{chat_id: int, chat_type: string, chat_title: ?string, chat_username: ?string}
     */
    protected function getPeerMetadata(Message $message): array
    {
        $type = 'private';
        $title = null;
        $username = null;

        try {
            $info = $this->client()->getFullInfo($message->chatId);
            $type = $info['type'] ?? 'private';
            $title = $info['User']['first_name'] ?? $info['Chat']['title'] ?? null;
            $username = $info['User']['username'] ?? $info['Chat']['username'] ?? null;
        } catch (Throwable) {
            // Metadata is best-effort; chat_id capture is authoritative.
        }

        return [
            'chat_id' => (int) $message->chatId,
            'chat_type' => $type,
            'chat_title' => $title,
            'chat_username' => $username,
        ];
    }

    protected function syncRoxiAccountStatus(TelegramAccountStatus $status): void
    {
        TelegramAccount::query()
            ->where('method', 'roxi')
            ->where('is_system', true)
            ->update(['status' => $status->value]);
    }
}
