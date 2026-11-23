<?php

namespace App\Domains\Files\Data;

readonly class UpdateFileDescriptionData
{
    public function __construct(public ?string $description) {}
}
