<?php

namespace App\Domains\Telegram\Data;

final readonly class ConfigureConnectionEncryptionData
{
    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<string, mixed>|null  $previousEnvelope
     */
    public function __construct(public array $envelope, public ?array $previousEnvelope = null, public ?string $scope = null) {}
}
