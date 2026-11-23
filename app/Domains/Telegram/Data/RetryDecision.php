<?php

namespace App\Domains\Telegram\Data;

use App\Domains\Telegram\Runtime\TelegramErrors;

/**
 * Result of classifying a Telegram failure.
 */
final readonly class RetryDecision
{
    public function __construct(
        public bool $retryable,
        public ?int $delaySeconds,
        public string $kind,
    ) {}

    /**
     * Human-safe error label recorded on operations.
     */
    public function errorLabel(string $message): string
    {
        return sprintf('[%s] %s', $this->kind, TelegramErrors::message($message));
    }
}
