<?php

namespace App\Domains\Telegram\Jobs;

use App\Domains\Files\Models\File;
use App\Domains\Telegram\Runtime\ErrorClassifier;
use App\Domains\Telegram\Runtime\RuntimeNotAvailable;
use App\Domains\Telegram\StorageManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Reconciliation sweep (spec §43): samples uploaded files and asks the
 * driver whether the Telegram message still exists. Mismatches are FLAGGED
 * in `files.metadata.reconciliation` — never auto-deleted on first
 * observation, and temporary Telegram errors never flag anything.
 */
#[Connection('redis')]
#[Queue('telegram')]
class ReconcileTelegramStorage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $batchSize = 100,
    ) {}

    public function handle(StorageManager $storageManager): int
    {
        $files = File::query()
            ->where('status', 'uploaded')
            ->whereNotNull('telegram_message_id')
            ->orderByRaw('CASE WHEN last_reconciled_at IS NULL THEN 0 ELSE 1 END')->orderBy('last_reconciled_at')->orderBy('id')
            ->limit(max(1, $this->batchSize))
            ->get();

        $flagged = 0;
        $checked = 0;

        foreach ($files as $file) {
            if ($file->connection === null) {
                continue;
            }

            $checked++;

            try {
                $exists = $storageManager->for($file->connection)->exists($file);
            } catch (RuntimeNotAvailable) {
                // Worker runtime is down: transient — never flag.
                break;
            } catch (Throwable $e) {
                $decision = ErrorClassifier::classify($e);

                if ($decision->retryable) {
                    $this->record($file, null);

                    continue;
                }

                $exists = null;
            }

            $this->record($file, $exists);

            if ($exists === false) {
                $flagged++;
            }
        }

        return $flagged;
    }

    private function record(File $file, ?bool $exists): void
    {
        $metadata = $file->metadata ?? [];

        if ($exists) {
            unset($metadata['reconciliation']);
        } elseif ($exists === false) {
            $metadata['reconciliation'] = [
                'flagged_at' => now()->toIso8601String(),
                'kind' => 'message_missing',
            ];
        }

        $file->timestamps = false;
        $file->forceFill(['metadata' => $exists === null ? $file->metadata : $metadata, 'last_reconciled_at' => now()])->save();
        $file->timestamps = true;
    }
}
