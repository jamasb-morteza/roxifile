<?php

namespace App\Domains\Telegram\Services;

use App\Domains\Telegram\Data\ConnectionVerification;
use App\Domains\Telegram\Enums\ChatType;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\RoxiRuntime;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VerifyRoxiStorageChat
{
    public function __construct(private readonly RoxiRuntime $runtime) {}

    public function handle(TelegramConnection $connection): ConnectionVerification
    {
        $chatId = $connection->chat_id ?? ($connection->metadata['created_chat_id'] ?? null)
            ?? $this->resolveChat((string) ($connection->metadata['chat_ref'] ?? ''));
        $verification = $this->inspect($connection, (int) $chatId);
        $this->testFileRoundtrip((int) $chatId, $connection->id);

        return $verification;
    }

    public function inspect(TelegramConnection $connection, int $chatId): ConnectionVerification
    {
        if (empty($connection->metadata['identity_verified_at']) || $connection->target_user_id === null) {
            throw new \InvalidArgumentException('Verify your Telegram identity before connecting a storage chat.');
        }

        $info = $this->runtime->getFullInfo($chatId);
        $type = ($info['type'] ?? '') === 'chat' ? ChatType::Group : ChatType::tryFrom((string) ($info['type'] ?? ''));
        if (! in_array($type, [ChatType::Group, ChatType::Supergroup, ChatType::Channel], true)) {
            throw new \InvalidArgumentException('Choose a group or channel for file storage. Private messages are not supported.');
        }
        if (! empty($info['Chat']['left']) || ! empty($info['Chat']['kicked']) || ! empty($info['Chat']['deactivated'])) {
            throw new \InvalidArgumentException('Add Roxi to this group or channel as an administrator first.');
        }

        if ($type === ChatType::Group) {
            $full = $this->runtime->methodCallAsyncRead('messages.getFullChat', ['chat_id' => -$chatId]);
            $participants = $full['full_chat']['participants']['participants'] ?? [];
            $this->requireGroupAdministrator($participants, $connection->target_user_id, 'You must own or administer this group.');
            $self = $this->runtime->getInfo('me');
            $this->requireGroupAdministrator($participants, (int) ($self['bot_api_id'] ?? 0), 'Add Roxi as an administrator of this group.');
        } else {
            $user = $this->runtime->methodCallAsyncRead('channels.getParticipant', ['channel' => $chatId, 'participant' => $connection->target_user_id]);
            $this->requireAdministrator($user['participant'] ?? [], ['channelParticipantCreator', 'channelParticipantAdmin'], 'You must own or administer this channel or supergroup.');
            $roxi = $this->runtime->methodCallAsyncRead('channels.getParticipant', ['channel' => $chatId, 'participant' => 'me']);
            $this->requireAdministrator($roxi['participant'] ?? [], ['channelParticipantCreator', 'channelParticipantAdmin'], 'Add Roxi as an administrator with permission to post files and delete messages.');
            if (($roxi['participant']['_'] ?? '') !== 'channelParticipantCreator' && empty($roxi['participant']['admin_rights']['delete_messages'])) {
                throw new \InvalidArgumentException('Give Roxi permission to delete messages in this chat.');
            }
            if ($type === ChatType::Channel && ($roxi['participant']['_'] ?? '') !== 'channelParticipantCreator'
                && empty($roxi['participant']['admin_rights']['post_messages'])) {
                throw new \InvalidArgumentException('Give Roxi permission to post messages in this channel.');
            }
        }

        return new ConnectionVerification(
            ok: true,
            chatId: $chatId,
            chatType: $type,
            chatTitle: $info['Chat']['title'] ?? null,
            chatUsername: $info['Chat']['username'] ?? null,
        );
    }

    private function resolveChat(string $reference): int
    {
        $reference = trim($reference);
        if (preg_match('~^(?:https://)?t\.me/(?:\+|joinchat/)([A-Za-z0-9_-]+)$~', $reference, $matches) === 1) {
            $invite = $this->runtime->methodCallAsyncRead('messages.checkChatInvite', ['hash' => $matches[1]]);
            if (($invite['_'] ?? '') !== 'chatInviteAlready') {
                throw new \InvalidArgumentException('Add Roxi to the chat as an administrator before validating this invite link.');
            }
            $info = $this->runtime->getInfo($invite['chat']);
        } elseif (preg_match('~^(?:https://t\.me/|@)?[A-Za-z][A-Za-z0-9_]{3,31}$~', $reference) === 1) {
            $info = $this->runtime->getInfo(preg_replace('~^https://t\.me/~', '@', $reference) ?? $reference);
        } elseif (preg_match('/^-[1-9][0-9]{0,15}$/', $reference) === 1) {
            $info = $this->runtime->getInfo((int) $reference);
        } else {
            throw new \InvalidArgumentException('Enter a Telegram invite link, @username, or negative chat ID.');
        }

        $chatId = (int) ($info['bot_api_id'] ?? 0);
        if ($chatId >= 0) {
            throw new \InvalidArgumentException('Choose a group or channel for file storage. Private messages are not supported.');
        }

        return $chatId;
    }

    /** @param array<string, mixed> $participant
     * @param  list<string>  $roles
     */
    private function requireAdministrator(array $participant, array $roles, string $message): void
    {
        if (! in_array($participant['_'] ?? '', $roles, true)) {
            throw new \InvalidArgumentException($message);
        }
    }

    /** @param list<array<string, mixed>> $participants */
    private function requireGroupAdministrator(array $participants, int $userId, string $message): void
    {
        foreach ($participants as $participant) {
            if (($participant['user_id'] ?? null) === $userId && in_array($participant['_'] ?? '', ['chatParticipantCreator', 'chatParticipantAdmin'], true)) {
                return;
            }
        }

        throw new \InvalidArgumentException($message);
    }

    private function testFileRoundtrip(int $chatId, int $connectionId): void
    {
        $bytes = 'RoxiFile storage verification '.Str::uuid();
        $name = 'roxifile-connection-test.txt';
        $uploaded = $this->runtime->uploadFromCallable(
            static fn (int $offset, int $size): string => substr($bytes, $offset, $size),
            strlen($bytes), 'text/plain', $name, static function (float $percent): void {}, true,
        );
        $sent = $this->runtime->methodCallAsyncRead('messages.sendMedia', [
            'peer' => $chatId,
            'media' => ['_' => 'inputMediaUploadedDocument', 'file' => $uploaded, 'mime_type' => 'text/plain',
                'attributes' => [['_' => 'documentAttributeFilename', 'file_name' => $name]]],
            'random_id' => random_int(1, PHP_INT_MAX),
            'message' => 'RoxiFile connection test — this file will be removed automatically.',
        ]);
        $messageId = 0;
        foreach ($sent['updates'] ?? [] as $update) {
            if (in_array($update['_'] ?? '', ['updateNewMessage', 'updateNewChannelMessage'], true)) {
                $messageId = (int) ($update['message']['id'] ?? 0);
                break;
            }
        }
        if (($sent['_'] ?? '') === 'updateShortSentMessage') {
            $messageId = (int) ($sent['id'] ?? 0);
        }
        if ($messageId <= 0) {
            throw new \RuntimeException('Telegram did not confirm the test file. Please try validation again.');
        }

        Storage::disk('local')->makeDirectory('telegram-download');
        $path = Storage::disk('local')->path('telegram-download/connection-test-'.$connectionId.'-'.Str::uuid().'.txt');
        try {
            $messages = $this->runtime->getMessages($chatId, [$messageId]);
            $message = null;
            foreach ($messages['messages'] ?? [] as $candidate) {
                if (($candidate['id'] ?? null) === $messageId) {
                    $message = $candidate;
                    break;
                }
            }
            if (! isset($message['media'])) {
                throw new \RuntimeException('The test file could not be read from this chat.');
            }
            $this->runtime->downloadToFile($message['media'], $path);
            if (! is_file($path) || ! hash_equals(hash('sha256', $bytes), (string) hash_file('sha256', $path))) {
                throw new \RuntimeException('The downloaded test file did not match the uploaded file.');
            }
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            $this->runtime->deleteMessages($chatId, [$messageId]);
        }
    }
}
