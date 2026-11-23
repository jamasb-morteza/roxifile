<?php

namespace App\Console\Commands;

use App\Domains\Files\Enums\FileOperationStatus;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Telegram\Services\TelegramTempStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled cleanup of abandoned temporary files (spec §47): expired upload
 * temp copies, stale download copies, and files whose operation reached a
 * permanent failure. Active operations are never touched.
 */
class TelegramCleanupUploadsCommand extends Command
{
    protected $signature = 'telegram:cleanup-uploads';

    protected $description = 'Remove abandoned/stale temporary Telegram upload and download files';

    public function handle(TelegramTempStorage $tempStorage): int
    {
        $removed = 0;
        FileOperation::query()->select(['id', 'status', 'metadata', 'completed_at', 'reserved_temp_bytes'])
            ->where(fn ($query) => $query->whereNotNull('metadata->temp_path')->orWhereNotNull('metadata->downloaded_path')->orWhere('reserved_temp_bytes', '>', 0))
            ->chunkById(200, function ($operations) use ($tempStorage, &$removed): void {
                foreach ($operations as $operation) {
                    DB::transaction(function () use ($operation, $tempStorage, &$removed): void {
                        $operation = FileOperation::query()->lockForUpdate()->find($operation->id);
                        if ($operation === null) {
                            return;
                        }
                        $metadata = $operation->metadata ?? [];
                        $paths = array_values(array_filter([
                            $metadata['temp_path'] ?? null, $metadata['downloaded_path'] ?? null,
                        ], is_string(...)));
                        $cacheExpired = $operation->status === FileOperationStatus::Completed
                            && is_string($metadata['expires_at'] ?? null) && now()->gte($metadata['expires_at']);
                        $failureExpired = in_array($operation->status, [FileOperationStatus::Failed, FileOperationStatus::Cancelled], true)
                            && $operation->completed_at?->lte(now()->subHour());
                        if ($cacheExpired || $failureExpired) {
                            foreach ($paths as $path) {
                                if ($tempStorage->exists($path)) {
                                    $tempStorage->delete($path);
                                    $removed++;
                                }
                            }
                            $operation->forceFill([
                                'metadata' => array_merge($metadata, ['temp_path' => null, 'downloaded_path' => null]),
                                'reserved_temp_bytes' => 0,
                            ])->save();

                        }
                    });
                }
            });

        $removed += $tempStorage->sweepExpired(fn (string $path): bool => FileOperation::query()
            ->where(fn ($query) => $query->where('metadata->temp_path', $path)->orWhere('metadata->downloaded_path', $path))->exists());

        $this->info("Removed {$removed} temporary file(s).");

        return self::SUCCESS;
    }
}
