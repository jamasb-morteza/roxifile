<?php

namespace App\Domains\Telegram\Services;

use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\RoxiRuntime;
use danog\DialogId\DialogId;
use danog\MadelineProto\RPCErrorException;
use Illuminate\Support\Facades\DB;
use Throwable;

class CreateRoxiStorageChat
{
    public function __construct(private readonly RoxiRuntime $runtime) {}

    public function handle(TelegramConnection $connection): bool
    {
        $this->runtime->getInfo('me');
        $connection->refresh();
        $chatId = $connection->metadata['created_chat_id'] ?? null;
        if ($chatId === null) {
            $title = (string) ($connection->metadata['group_name'] ?? 'roxifile-'.$connection->id);
            if (! empty($connection->metadata['created_chat_started_at'])) {
                $chatId = $this->findPreviouslyCreatedChat($connection, $title);
                if ($chatId === null) {
                    throw new \RuntimeException('Telegram did not confirm the previous group creation, and no matching group could be recovered. Check with RoxiFile support before trying again.');
                }
            } else {
                DB::transaction(function () use ($connection): void {
                    $current = TelegramConnection::query()->lockForUpdate()->findOrFail($connection->id);
                    if (! empty($current->metadata['created_chat_started_at'])) {
                        throw new \RuntimeException('Telegram group creation is already in progress. Please retry.');
                    }
                    $current->forceFill(['metadata' => array_merge($current->metadata ?? [], ['created_chat_started_at' => now()->toIso8601String()])])->save();
                });
                try {
                    $created = $this->runtime->methodCallAsyncRead('channels.createChannel', [
                        'megagroup' => true, 'title' => $title, 'about' => 'Private file storage managed through RoxiFile.',
                    ]);
                } catch (RPCErrorException $error) {
                    $this->saveMetadata($connection, ['created_chat_started_at' => null]);
                    throw $error;
                }
                foreach ($created['chats'] ?? [] as $chat) {
                    if (($chat['_'] ?? '') === 'channel' && ! empty($chat['megagroup']) && isset($chat['id'])) {
                        $returnedId = (int) $chat['id'];
                        $chatId = DialogId::isSupergroupOrChannel($returnedId)
                            ? $returnedId
                            : DialogId::fromSupergroupOrChannelId($returnedId);
                        break;
                    }
                }
                if ($chatId === null) {
                    $chatId = $this->findPreviouslyCreatedChat($connection, $title);
                    if ($chatId === null) {
                        throw new \RuntimeException('Telegram did not confirm the created group. Retry to check whether the group was created.');
                    }
                }
            }
            $this->saveMetadata($connection, ['created_chat_id' => $chatId, 'created_chat_title' => $title]);
        }
        $chatId = (int) $chatId;
        $this->runtime->methodCallAsyncRead('folders.editPeerFolders', [
            'folder_peers' => [['_' => 'inputFolderPeer', 'peer' => $chatId, 'folder_id' => 1]],
        ]);
        if (empty($connection->metadata['created_chat_photo_set'])) {
            try {
                $this->runtime->methodCallAsyncRead('channels.editPhoto', [
                    'channel' => $chatId,
                    'photo' => ['_' => 'inputChatUploadedPhoto', 'file' => public_path('icon.png')],
                ]);
                $this->saveMetadata($connection, ['created_chat_photo_set' => true]);
            } catch (Throwable $error) {
                report($error);
            }
        }
        $userId = (int) $connection->target_user_id;
        if (! $this->isMember($chatId, $userId)) {
            try {
                $this->runtime->methodCallAsyncRead('channels.inviteToChannel', ['channel' => $chatId, 'users' => [$userId]]);
            } catch (Throwable $error) {
                if (! preg_match('/USER_PRIVACY_RESTRICTED|USER_NOT_MUTUAL_CONTACT|USER_CHANNELS_TOO_MUCH|USER_BLOCKED|USER_KICKED|CHAT_MEMBER_ADD_FAILED/', $error->getMessage())) {
                    throw $error;
                }
            }
            if (! $this->isMember($chatId, $userId)) {
                if (empty($connection->metadata['created_chat_invite_link']) || (int) ($connection->metadata['created_chat_invite_expires_at'] ?? 0) <= now()->timestamp) {
                    $expiresAt = now()->addDay()->timestamp;
                    $invite = $this->runtime->methodCallAsyncRead('messages.exportChatInvite', ['peer' => $chatId, 'expire_date' => $expiresAt, 'usage_limit' => 1]);
                    $link = $invite['link'] ?? null;
                    if (! is_string($link) || ! str_starts_with($link, 'https://t.me/')) {
                        throw new \RuntimeException('Telegram could not create a join link. Try again.');
                    }
                    $this->saveMetadata($connection, ['created_chat_invite_link' => $link, 'created_chat_invite_expires_at' => $expiresAt]);
                }

                return false;
            }
        }
        try {
            $this->runtime->methodCallAsyncRead('channels.editAdmin', [
                'channel' => $chatId, 'user_id' => $userId,
                'admin_rights' => ['_' => 'chatAdminRights', 'change_info' => true, 'delete_messages' => true, 'invite_users' => true, 'pin_messages' => true],
                'rank' => 'RoxiFile admin',
            ]);
        } catch (Throwable $error) {
            if (! str_contains($error->getMessage(), 'CHAT_NOT_MODIFIED')) {
                throw $error;
            }
        }

        return true;
    }

    private function findPreviouslyCreatedChat(TelegramConnection $connection, string $title): ?int
    {
        $reserved = [];
        foreach (TelegramConnection::query()->where('telegram_account_id', $connection->telegram_account_id)->whereKeyNot($connection->id)->get(['chat_id', 'metadata']) as $other) {
            foreach ([$other->chat_id, $other->metadata['created_chat_id'] ?? null] as $id) {
                if ($id !== null) {
                    $reserved[(int) $id] = true;
                }
            }
        }

        $matches = [];
        foreach ($this->runtime->getDialogIds() as $dialogId) {
            if (! DialogId::isSupergroupOrChannel($dialogId) || isset($reserved[$dialogId])) {
                continue;
            }
            $info = $this->runtime->getInfo($dialogId);
            if (($info['Chat']['title'] ?? null) !== $title || empty($info['Chat']['megagroup'])) {
                continue;
            }
            $participant = $this->runtime->methodCallAsyncRead('channels.getParticipant', [
                'channel' => $dialogId, 'participant' => 'me',
            ]);
            if (($participant['participant']['_'] ?? null) === 'channelParticipantCreator') {
                $matches[] = $dialogId;
            }
        }

        if (count($matches) > 1) {
            throw new \RuntimeException('Multiple Telegram groups match the previous creation. Check with RoxiFile support before trying again.');
        }

        return $matches[0] ?? null;
    }

    /** @phpstan-impure */
    private function isMember(int $chatId, int $userId): bool
    {
        try {
            $result = $this->runtime->methodCallAsyncRead('channels.getParticipant', ['channel' => $chatId, 'participant' => $userId]);

            return in_array($result['participant']['_'] ?? '', ['channelParticipant', 'channelParticipantSelf', 'channelParticipantAdmin', 'channelParticipantCreator'], true);
        } catch (Throwable $error) {
            if (! str_contains($error->getMessage(), 'USER_NOT_PARTICIPANT')) {
                throw $error;
            }

            return false;
        }
    }

    /** @param array<string, mixed> $attributes */
    private function saveMetadata(TelegramConnection $connection, array $attributes): void
    {
        DB::transaction(function () use ($connection, $attributes): void {
            $current = TelegramConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $current->forceFill(['metadata' => array_merge($current->metadata ?? [], $attributes)])->save();
        });
        $connection->refresh();
    }
}
