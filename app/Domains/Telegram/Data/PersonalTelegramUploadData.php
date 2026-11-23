<?php

namespace App\Domains\Telegram\Data;

final readonly class PersonalTelegramUploadData
{
    public function __construct(
        public int $operationId,
        public int $fileId,
        public int $fileSize,
        public string $mimeType,
        public string $fileName,
        public int $randomId,
        public string $caption,
    ) {}
}
