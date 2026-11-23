<?php

namespace App\Domains\Telegram\Data;

final readonly class SetupRoxiStorageChatData
{
    public function __construct(public string $mode, public ?string $groupName = null) {}
}
