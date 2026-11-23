<?php

namespace App\Domains\Telegram\Runtime;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

class TelegramWorkerStreamRequestHandler implements RequestHandler
{
    public function __construct(
        private readonly TelegramUploadStreamRequestHandler $uploads,
        private readonly TelegramDownloadStreamRequestHandler $downloads,
    ) {}

    public function handleRequest(Request $request): Response
    {
        return match ($request->getMethod()) {
            'PUT' => $this->uploads->handleRequest($request),
            'GET', 'HEAD' => $this->downloads->handleRequest($request),
            default => new Response(HttpStatus::NOT_FOUND),
        };
    }
}
