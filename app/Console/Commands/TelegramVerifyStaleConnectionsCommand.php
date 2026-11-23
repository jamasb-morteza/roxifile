<?php

namespace App\Console\Commands;

use App\Domains\Telegram\Jobs\VerifyTelegramConnectionJob;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Console\Command;

/**
 * Periodic connection re-verification (spec §43): queues verify jobs for
 * connections with a stale (or missing) last_verified_at, in small batches.
 */
class TelegramVerifyStaleConnectionsCommand extends Command
{
    protected $signature = 'telegram:verify-stale
        {--batch=50 : Maximum number of connections to queue per run}
        {--hours=24 : Staleness threshold in hours}';

    protected $description = 'Queue re-verification for Telegram connections with stale last_verified_at';

    public function handle(): int
    {
        $batch = max(1, (int) $this->option('batch'));
        $hours = max(1, (int) $this->option('hours'));

        $connections = TelegramConnection::query()
            ->whereNull('deleted_at')->where('status', '!=', 'disabled')
            ->where(function ($query) use ($hours): void {
                $query
                    ->whereNull('last_verified_at')
                    ->orWhere('last_verified_at', '<', now()->subHours($hours));
            })
            ->orderBy('last_verified_at')
            ->limit($batch)
            ->pluck('id');

        foreach ($connections as $connectionId) {
            dispatch(new VerifyTelegramConnectionJob($connectionId));
        }

        $this->info("Queued {$connections->count()} connection(s) for re-verification.");

        return self::SUCCESS;
    }
}
