<?php

namespace App\Domains\Telegram\Contracts;

use App\Domains\Telegram\Data\ResolvedPeer;

/**
 * The only surface for addressing Telegram peers (user accounts) from the
 * worker runtime: resolving a designated target and sending/retracting
 * plain text messages as Roxi. Worker-side only — never callable from HTTP
 * (spec Rule 1).
 */
interface TelegramPeerClient
{
    /**
     * Resolve a normalized target identifier (lowercase username or
     * "+"-prefixed phone) to a Telegram peer.
     *
     * @throws PeerResolutionFailed when the account cannot be resolved
     */
    public function resolveTarget(string $identifier): ResolvedPeer;

    /**
     * Send a plain text message to a chat, returning the delivered message id
     * (0 when the id could not be captured from the response).
     */
    public function sendMessage(int $chatId, string $text): int;

    /**
     * Delete a message previously sent by Roxi to the chat (revoke).
     */
    public function deleteSentMessage(int $chatId, int $messageId): void;
}
