<?php

namespace App\Domains\Telegram\Jobs;

use App\Domains\Telegram\Actions\RevokePersonalTelegramConnection;
use App\Domains\Telegram\Runtime\PersonalTelegramLoginException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

#[Connection('redis')]
#[Queue('telegram')]
class RevokePersonalTelegramConnectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $connectionId, public readonly int $accountId) {}

    public function handle(RevokePersonalTelegramConnection $action): void
    {
        try {
            $action->handle($this->connectionId, $this->accountId);
        } catch (PersonalTelegramLoginException $exception) {
            if (! $exception->retryable) {
                throw $exception;
            }

            $this->release($exception->retryAfterSeconds);
        }
    }

    public function failed(Throwable $exception): void
    {
        app(RevokePersonalTelegramConnection::class)->markFailed($this->connectionId, $this->accountId);
    }
}
