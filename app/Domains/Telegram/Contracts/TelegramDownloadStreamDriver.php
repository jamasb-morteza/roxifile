<?php

namespace App\Domains\Telegram\Contracts;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use App\Domains\Files\Models\File;

interface TelegramDownloadStreamDriver
{
    public function streamDownloadResponse(File $file, Request $request): Response;
}
