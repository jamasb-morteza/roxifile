<?php

namespace App\Domains\Telegram\Contracts;

use App\Domains\Telegram\Data\SendTelegramUploadStreamData;

interface TelegramUploadStreamTransport
{
    public function send(SendTelegramUploadStreamData $data): void;
}
