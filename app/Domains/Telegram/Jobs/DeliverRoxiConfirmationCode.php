<?php

namespace App\Domains\Telegram\Jobs;

use App\Domains\Telegram\Contracts\TelegramPeerClient;
use App\Domains\Telegram\Runtime\RuntimeNotAvailable;
use App\Domains\Telegram\Services\RoxiProvisioningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

#[Connection('redis')]
#[Queue('telegram')]
class DeliverRoxiConfirmationCode implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly int $connectionId,
        public readonly string $attemptId,
    ) {}

    public function handle(TelegramPeerClient $peerClient, RoxiProvisioningService $provisioning): void
    {
        try {
            $provisioning->deliverConfirmationCode($this->connectionId, $this->attemptId, $peerClient);
        } catch (RuntimeNotAvailable) {
            $this->release(10);
        }
    }

    public function failed(Throwable $exception): void
    {
        app(RoxiProvisioningService::class)->failConfirmationDelivery($this->connectionId, $this->attemptId);
    }
}
