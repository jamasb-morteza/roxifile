<?php

namespace App\Domains\Telegram\Actions;

use App\Domains\Telegram\Contracts\TelegramDownloadStreamTransport;
use App\Domains\Telegram\Data\StreamTelegramFileDownloadData;
use Symfony\Component\HttpFoundation\Response;

class StreamTelegramFileDownload
{
    public function __construct(private readonly TelegramDownloadStreamTransport $transport) {}

    public function handle(StreamTelegramFileDownloadData $data): Response
    {
        return $this->transport->stream($data);
    }
}
