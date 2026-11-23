<?php

namespace App\Domains\Identity\Data;

final readonly class GoogleIdentityData
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public bool $emailIsVerified,
    ) {}
}
