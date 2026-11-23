<?php

namespace App\Domains\Telegram\Runtime;

use Illuminate\Support\Facades\Queue;
use Throwable;

use function Amp\async;

class TelegramQueueConsumer
{
    private int $activeJobs = 0;

    private ?float $stoppingAt = null;

    public function __construct(private readonly WorkerHealth $health) {}

    public function tick(): void
    {
        if ($this->stoppingAt !== null) {
            return;
        }
        try {
            $limit = max(1, (int) config('telegram.worker.max_concurrent_operations', 4));
            while ($this->activeJobs < $limit) {
                $job = Queue::connection('redis')->pop((string) config('telegram.worker.queue', 'telegram'));
                if ($job === null) {
                    return;
                }
                $this->activeJobs++;
                async(function () use ($job): void {
                    try {
                        $job->fire();
                    } catch (Throwable $error) {
                        $this->health->recordError($error);
                        $job->fail(new \RuntimeException(TelegramErrors::message($error->getMessage())));
                    } finally {
                        $this->activeJobs--;
                    }
                });
            }
        } catch (Throwable $error) {
            $this->health->recordError($error);
        }
    }

    public function stop(): void
    {
        $this->stoppingAt ??= microtime(true);
    }

    public function isStopping(): bool
    {
        return $this->stoppingAt !== null;
    }

    public function isDrained(): bool
    {
        return $this->activeJobs === 0;
    }

    public function shouldExit(): bool
    {
        return $this->stoppingAt !== null && ($this->isDrained()
            || microtime(true) - $this->stoppingAt >= max(1, (int) config('telegram.worker.drain_seconds', 60)));
    }
}
