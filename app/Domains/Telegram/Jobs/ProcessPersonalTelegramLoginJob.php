<?php

namespace App\Domains\Telegram\Jobs;

use App\Domains\Telegram\Actions\ProcessPersonalTelegramLogin;
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
class ProcessPersonalTelegramLoginJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $accountId) {}

    public function handle(ProcessPersonalTelegramLogin $action): void
    {
        try {
            $action->handle($this->accountId);
        } catch (PersonalTelegramLoginException $exception) {
            if (! $exception->retryable) {
                throw $exception;
            }

            $this->release($exception->retryAfterSeconds);
        }
    }

    public function failed(Throwable $exception): void
    {
        app(ProcessPersonalTelegramLogin::class)->markFailed($this->accountId);
    }
}
