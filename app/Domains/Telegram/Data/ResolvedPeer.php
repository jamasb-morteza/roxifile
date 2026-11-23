<?php

namespace App\Domains\Telegram\Data;

/**
 * A Telegram peer resolved by the worker from a designated target identifier.
 */
final readonly class ResolvedPeer
{
    public function __construct(
        public int $userId,
        public ?string $username = null,
        public ?string $title = null,
    ) {}
}
