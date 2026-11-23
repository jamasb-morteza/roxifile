<?php

namespace App\Domains\Telegram\Actions;

use App\Domains\Telegram\Contracts\TelegramUploadStreamTransport;
use App\Domains\Telegram\Data\SendTelegramUploadStreamData;

class SendTelegramUploadStream
{
    public function __construct(private readonly TelegramUploadStreamTransport $transport) {}

    public function handle(SendTelegramUploadStreamData $data): void
    {
        $this->transport->send($data);
    }
}
