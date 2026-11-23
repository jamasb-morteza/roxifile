<?php

namespace App\Domains\Files\Data;

readonly class CopyFileData
{
    public function __construct(public ?int $directoryId, public ?string $name, public ?string $requestId) {}
}
