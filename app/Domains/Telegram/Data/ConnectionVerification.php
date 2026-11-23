<?php

namespace App\Domains\Telegram\Data;

use App\Domains\Telegram\Enums\ChatType;

/**
 * Result of verifying a Telegram connection.
 */
final readonly class ConnectionVerification
{
    public function __construct(
        public bool $ok,
        public ?int $chatId = null,
        public ?ChatType $chatType = null,
        public ?string $chatTitle = null,
        public ?string $chatUsername = null,
        public ?string $error = null,
    ) {}

    public static function failed(string $error): self
    {
        return new self(ok: false, error: $error);
    }
}
