<?php

namespace App\Domains\Files\Data;

/**
 * Controller→action contract for directory creation.
 */
final readonly class CreateDirectoryData
{
    public function __construct(
        public string $name,
        public ?int $parentId,
    ) {}
}
