<?php

namespace App\Domains\Telegram\Runtime;

use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Single-runtime guarantee for the Telegram worker (spec §12).
 *
 * Wraps a Laravel cache lock with an owner token; supports atomic refresh on
 * stores that implement it (Redis) and graceful degradation on stores that
 * do not (the TTL then acts as the sole liveness window).
 */
class WorkerLock
{
    private ?\Illuminate\Contracts\Cache\Lock $lock = null;

    private readonly string $owner;

    public function __construct(
        private readonly string $name,
        private readonly int $ttlSeconds,
    ) {
        $this->owner = Str::ulid()->toBase32();
    }

    /**
     * Try to acquire the runtime lock. Returns false when another worker holds it.
     */
    public function acquire(): bool
    {
        $lock = Cache::lock($this->name, $this->ttlSeconds, $this->owner);

        try {
            if (! $lock->get()) {
                return false;
            }
        } catch (LockTimeoutException) {
            return false;
        }

        $this->lock = $lock;

        return true;
    }

    /**
     * Atomically extend the lock TTL when the store supports it.
     */
    public function refresh(): bool
    {
        // Only concrete lock stores implement atomic refresh (Redis, ...).
        if (! $this->lock instanceof Lock) {
            return false;
        }

        try {
            return $this->lock->refresh($this->ttlSeconds);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Release the lock if still owned by this process.
     */
    public function release(): void
    {
        $this->lock?->release();
        $this->lock = null;
    }

    public function owner(): string
    {
        return $this->owner;
    }
}
