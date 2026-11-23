<?php

namespace App\Domains\Telegram\Contracts;

use App\Domains\Telegram\Data\StreamTelegramFileDownloadData;
use Symfony\Component\HttpFoundation\Response;

interface TelegramDownloadStreamTransport
{
    public function stream(StreamTelegramFileDownloadData $data): Response;
}
