<?php

namespace App\Domains\Files\Data;

use App\Domains\Files\Enums\FileStatus;

readonly class QueueTelegramMetadataData
{
    public function __construct(
        public int $fileId,
        public FileStatus $status,
        public ?int $telegramMessageId,
    ) {}
}
