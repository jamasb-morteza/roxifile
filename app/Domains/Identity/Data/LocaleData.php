<?php

namespace App\Domains\Identity\Data;

readonly class LocaleData
{
    public function __construct(public string $locale) {}
}
