<?php

namespace App\Domains\Identity\Data;

readonly class UpdateUserPasswordData
{
    public function __construct(
        public string $password,
    ) {
        //
    }
}
