<?php

namespace App\Domains\Telegram\Runtime;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Illuminate\Support\Facades\Log;

class TelegramUploadStreamServer
{
    private ?SocketHttpServer $server = null;

    public function start(): void
    {
        if ($this->server !== null) {
            return;
        }

        $logger = Log::channel(config('logging.default'))->getLogger();
        $server = SocketHttpServer::createForDirectAccess(
            $logger,
            enableCompression: false,
            connectionLimit: 16,
            connectionLimitPerIp: 8,
            concurrencyLimit: 4,
            allowedMethods: ['PUT', 'GET', 'HEAD'],
        );
        $server->expose((string) config('telegram.worker.upload_bridge_address', '127.0.0.1:18751'));
        $server->start(app(TelegramWorkerStreamRequestHandler::class), new DefaultErrorHandler);

        $this->server = $server;
    }

    public function stop(): void
    {
        $this->server?->stop();
        $this->server = null;
    }
}
