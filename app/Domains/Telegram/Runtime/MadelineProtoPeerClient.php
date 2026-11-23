<?php

namespace App\Domains\Telegram\Runtime;

use App\Domains\Telegram\Contracts\PeerResolutionFailed;
use App\Domains\Telegram\Contracts\TelegramPeerClient;
use App\Domains\Telegram\Data\ResolvedPeer;
use Throwable;

/**
 * MadelineProto-backed TelegramPeerClient. Runs inside the telegram:worker
 * process only; every call goes through the already-running Roxi runtime
 * client (spec Rules 1-2).
 */
class MadelineProtoPeerClient implements TelegramPeerClient
{
    public function __construct(
        private readonly RoxiRuntime $runtime,
    ) {}

    public function resolveTarget(string $identifier): ResolvedPeer
    {
        $client = $this->runtime;

        try {
            if (str_starts_with($identifier, '+')) {
                return $this->resolveByPhone($client, $identifier);
            }

            return $this->resolveByUsername($client, $identifier);
        } catch (PeerResolutionFailed $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PeerResolutionFailed(
                'That Telegram account could not be reached.',
                0,
                $e,
            );
        }
    }

    public function sendMessage(int $chatId, string $text): int
    {
        $updates = $this->runtime->methodCallAsyncRead('messages.sendMessage', [
            'peer' => $chatId,
            'message' => $text,
            'random_id' => random_int(1, PHP_INT_MAX),
        ]);

        foreach ((array) ($updates['updates'] ?? []) as $update) {
            $message = $update['message'] ?? null;

            if (is_array($message) && isset($message['id'])) {
                return (int) $message['id'];
            }
        }

        return 0;
    }

    public function deleteSentMessage(int $chatId, int $messageId): void
    {
        $this->runtime->methodCallAsyncRead('messages.deleteMessages', [
            'id' => [$messageId],
            'revoke' => true,
        ]);
    }

    private function resolveByUsername(RoxiRuntime $client, string $username): ResolvedPeer
    {
        $info = (array) $client->getInfo(ltrim($username, '@'));
        $user = (array) ($info['User'] ?? $info);
        $userId = (int) ($info['user_id'] ?? ($user['id'] ?? 0));

        if ($userId === 0) {
            throw new PeerResolutionFailed('That Telegram account could not be reached.');
        }

        $resolvedUsername = isset($user['username']) ? (string) $user['username'] : $username;

        return new ResolvedPeer(
            userId: $userId,
            username: $resolvedUsername !== '' ? $resolvedUsername : null,
            title: $this->peerTitle($user),
        );
    }

    private function resolveByPhone(RoxiRuntime $client, string $phone): ResolvedPeer
    {
        $imported = (array) $client->methodCallAsyncRead('contacts.importContacts', [
            'contacts' => [[
                '_' => 'inputPhoneContact',
                'client_id' => (string) random_int(1, PHP_INT_MAX),
                'phone' => ltrim($phone, '+'),
                'first_name' => 'RoxiFile target',
                'last_name' => '',
            ]],
        ]);

        $userId = (int) ($imported['imported'][0]['user_id'] ?? 0);
        $user = [];
        foreach ($imported['users'] ?? [] as $candidate) {
            if ((int) ($candidate['id'] ?? 0) === $userId) {
                $user = $candidate;
                break;
            }
        }

        if ($userId === 0) {
            throw new PeerResolutionFailed(
                'That mobile number is not reachable on Telegram. Ask the account to make its number discoverable, or use its @username.',
            );
        }

        return new ResolvedPeer(
            userId: $userId,
            username: isset($user['username']) ? (string) $user['username'] : null,
            title: $this->peerTitle($user),
        );
    }

    /**
     * @param  array<string, mixed>  $peer
     */
    private function peerTitle(array $peer): ?string
    {
        if (isset($peer['title']) && $peer['title'] !== '') {
            return (string) $peer['title'];
        }

        $name = trim(($peer['first_name'] ?? '').' '.($peer['last_name'] ?? ''));

        return $name !== '' ? $name : null;
    }
}
