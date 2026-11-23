<?php

namespace App\Domains\Telegram\Data;

final readonly class SendTelegramUploadStreamData
{
    public function __construct(
        public int $operationId,
        public string $sourcePath,
        public int $size,
    ) {}
}
