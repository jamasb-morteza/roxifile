<?php

namespace App\Domains\Telegram\Runtime;

use App\Domains\Telegram\Models\TelegramWorkerStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Maintains the singleton telegram_worker_status row (health heartbeats).
 */
class WorkerHealth
{
    public function __construct(
        private readonly ?string $logChannel = null,
    ) {}

    /**
     * Record (or create) the singleton status row for this worker process.
     */
    public function register(bool $sessionLoaded): void
    {
        $status = TelegramWorkerStatus::query()->find(1) ?? new TelegramWorkerStatus;
        $status->forceFill([
            'id' => 1,
            'pid' => getmypid(),
            'hostname' => gethostname() ?: 'unknown',
            'started_at' => Carbon::now(),
            'session_loaded' => $sessionLoaded,
            'roxi_connected' => false,
            'last_error' => null,
            'last_error_at' => null,
        ])->save();
    }

    /**
     * Write a liveness heartbeat, optionally with fresh runtime state.
     */
    public function heartbeat(bool $roxiConnected, bool $sessionLoaded): void
    {
        if (! TelegramWorkerStatus::query()->whereKey(1)->exists()) {
            $this->register($sessionLoaded);
        }

        $this->ownedStatus()->update([
            'pid' => getmypid(),
            'hostname' => gethostname() ?: 'unknown',
            'last_heartbeat_at' => Carbon::now(),
            'last_activity_at' => Carbon::now(),
            'roxi_connected' => $roxiConnected,
            'session_loaded' => $sessionLoaded,
        ]);
    }

    public function recordSuccessfulOperation(): void
    {
        $this->ownedStatus()->update([
            'last_successful_operation_at' => Carbon::now(),
        ]);
    }

    public function recordError(\Throwable $e): void
    {
        $reference = (string) Str::ulid();
        $this->ownedStatus()->update([
            'last_error' => '['.$reference.'] '.TelegramErrors::message($e->getMessage()),
            'last_error_at' => Carbon::now(),
        ]);

        Log::channel($this->logChannel ?? config('logging.default'))->error('Telegram worker error', [
            'reference' => $reference,
            'error' => TelegramErrors::message($e->getMessage()),
        ]);
    }

    public function recordReconnect(): void
    {
        $this->ownedStatus()->increment('reconnect_count');
    }

    public function markStopped(): void
    {
        TelegramWorkerStatus::query()->whereKey(1)->where('pid', getmypid())->where('hostname', gethostname() ?: 'unknown')->update([
            'pid' => null,
            'roxi_connected' => false,
            'session_loaded' => false,
            'last_heartbeat_at' => null,
        ]);
    }

    /** @return Builder<TelegramWorkerStatus> */
    private function ownedStatus(): Builder
    {
        return TelegramWorkerStatus::query()->whereKey(1)->where('pid', getmypid())->where('hostname', gethostname() ?: 'unknown');
    }
}
