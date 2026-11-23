<?php

namespace App\Domains\Telegram\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Singleton row (id = 1) written by the long-running Telegram worker.
 *
 * @property int $id
 * @property int|null $pid
 * @property string|null $hostname
 * @property Carbon|null $started_at
 * @property Carbon|null $last_heartbeat_at
 * @property bool $roxi_connected
 * @property bool $session_loaded
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $last_successful_operation_at
 * @property string|null $last_error
 * @property Carbon|null $last_error_at
 * @property int $reconnect_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TelegramWorkerStatus extends Model
{
    protected $table = 'telegram_worker_status';

    /**
     * Whether the worker heartbeat is fresh enough to count as running
     * (heartbeats every 15s; 45s tolerates three missed beats).
     */
    public function isRunning(): bool
    {
        return $this->last_heartbeat_at !== null
            && $this->last_heartbeat_at->gt(now()->subSeconds(45));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pid' => 'integer',
            'roxi_connected' => 'boolean',
            'session_loaded' => 'boolean',
            'started_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'last_successful_operation_at' => 'datetime',
            'last_error_at' => 'datetime',
            'reconnect_count' => 'integer',
        ];
    }
}
