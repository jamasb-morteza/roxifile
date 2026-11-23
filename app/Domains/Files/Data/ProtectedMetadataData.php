<?php

namespace App\Domains\Files\Data;

final readonly class ProtectedMetadataData
{
    /**
     * @param  array{version: int, salt: string, iv: string, ciphertext: string}  $metadata
     * @param  array{version: int, salt: string, iv: string, ciphertext: string}  $previousMetadata
     */
    public function __construct(public array $metadata, public array $previousMetadata) {}
}
