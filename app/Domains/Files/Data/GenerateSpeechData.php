<?php

declare(strict_types=1);

namespace App\Domains\Files\Data;

final readonly class GenerateSpeechData
{
    public function __construct(
        public string $text,
        public string $voice,
        public string $requestId,
    ) {}
}
