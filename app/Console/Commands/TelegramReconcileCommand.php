<?php

namespace App\Console\Commands;

use App\Domains\Telegram\Jobs\ReconcileTelegramStorage;
use Illuminate\Console\Command;

/**
 * Reconciliation sweep (spec §43): samples uploaded files and verifies the
 * Telegram message still exists. Inconsistencies are FLAGGED in file metadata
 * — never auto-deleted on first observation. The sweep runs on the telegram
 * queue inside the worker runtime.
 */
class TelegramReconcileCommand extends Command
{
    protected $signature = 'telegram:reconcile
        {--batch=100 : Number of files to sample}
        {--sync : Run the sweep inline instead of dispatching to the worker queue}';

    protected $description = 'Verify sampled files still exist on Telegram; flag inconsistencies';

    public function handle(): int
    {
        $batchSize = max(1, (int) $this->option('batch'));

        if ($this->option('sync')) {
            $flagged = app()->call([new ReconcileTelegramStorage($batchSize), 'handle']);

            $this->info("Reconcile sweep complete: {$flagged} file(s) flagged.");

            return self::SUCCESS;
        }

        dispatch(new ReconcileTelegramStorage($batchSize));

        $this->info('Reconcile sweep dispatched to the telegram queue.');

        return self::SUCCESS;
    }
}
