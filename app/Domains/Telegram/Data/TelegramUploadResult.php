<?php

namespace App\Domains\Telegram\Data;

/**
 * Identifiers returned by a successful Telegram upload.
 */
final readonly class TelegramUploadResult
{
    public function __construct(
        public int $telegramMessageId,
        public ?string $telegramMediaId = null,
        public ?string $telegramFileId = null,
    ) {
        if ($telegramMessageId <= 0) {
            throw new \RuntimeException('Telegram did not return a valid uploaded message identifier.');
        }
    }
}
