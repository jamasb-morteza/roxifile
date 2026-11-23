<?php

namespace App\Domains\Telegram\Runtime;

use Amp\SignalException;
use Revolt\EventLoop;
use Throwable;

/** Keeps ownership alive during both session bootstrap and yielding job I/O. */
class WorkerHeartbeat
{
    private ?string $watcher = null;

    public function __construct(
        private readonly WorkerLock $lock,
        private readonly WorkerHealth $health,
        private readonly OperationLease $leases,
        private readonly RoxiRuntime $runtime,
    ) {}

    public function start(int $intervalSeconds): void
    {
        $this->pulse();
        $this->watcher = EventLoop::unreference(EventLoop::repeat($intervalSeconds, $this->pulse(...)));
    }

    public function stop(): void
    {
        if ($this->watcher !== null) {
            EventLoop::cancel($this->watcher);
            $this->watcher = null;
        }
    }

    private function pulse(): void
    {
        try {
            if (! $this->lock->refresh() || ! $this->leases->refresh()) {
                throw new \RuntimeException('Runtime ownership lost.');
            }
            $running = $this->runtime->isRunning();
            $this->health->heartbeat($running, $running);
        } catch (Throwable) {
            $this->leases->invalidate();
            $this->runtime->unbind();
            RoxiEventHandler::$stopRequested = true;
            throw new SignalException('Runtime ownership lost; stopping the worker.');
        }
    }
}
