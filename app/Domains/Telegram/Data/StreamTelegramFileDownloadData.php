<?php

namespace App\Domains\Telegram\Data;

final readonly class StreamTelegramFileDownloadData
{
    public function __construct(
        public int $fileId,
        public int $ownerId,
        public bool $head,
        public ?string $range,
    ) {}
}
