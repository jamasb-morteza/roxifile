<?php

namespace App\Domains\Identity\Data;

readonly class UpdateProfileData
{
    public function __construct(
        public string $name,
        public string $email,
    ) {
        //
    }
}
