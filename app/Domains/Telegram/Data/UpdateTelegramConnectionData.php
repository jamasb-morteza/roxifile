<?php

namespace App\Domains\Telegram\Data;

final readonly class UpdateTelegramConnectionData
{
    public function __construct(public ?string $name = null, public ?bool $enabled = null, public ?bool $syncCaptionTags = null) {}
}
