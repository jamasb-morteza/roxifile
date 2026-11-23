<?php

namespace App\Console\Commands;

use App\Domains\Files\Enums\FileOperationStatus;
use App\Domains\Files\Enums\FileOperationType;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Telegram\Jobs\TelegramOperationJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TelegramRecoverOperationsCommand extends Command
{
    protected $signature = 'telegram:recover-operations';

    protected $description = 'Recover abandoned operations without repeating an upload with an unknown outcome';

    public function handle(): int
    {
        FileOperation::query()->whereIn('status', ['pending', 'retrying', 'processing', 'uploading', 'downloading'])
            ->where('updated_at', '<=', now()->subMinutes(2))
            ->where(fn ($query) => $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))
            ->eachById(function (FileOperation $candidate): void {
                $dispatch = DB::transaction(function () use ($candidate): bool {
                    $operation = FileOperation::query()->lockForUpdate()->findOrFail($candidate->id);

                    if (! in_array($operation->status->value, ['pending', 'retrying', 'processing', 'uploading', 'downloading'], true)) {
                        return false;
                    }
                    if ($operation->updated_at?->gt(now()->subMinutes(2))
                        || $operation->lease_expires_at?->isFuture()
                        || $operation->available_at?->isFuture()) {
                        return false;
                    }

                    $uncertain = in_array($operation->operation, [FileOperationType::Upload, FileOperationType::Copy], true)
                        && ! empty($operation->metadata['send_started_at'])
                        && empty($operation->metadata['upload_result']);
                    $operation->forceFill([
                        'status' => $uncertain ? FileOperationStatus::NeedsReview : FileOperationStatus::Pending,
                        'lease_token' => null,
                        'lease_expires_at' => null,
                        'error' => $uncertain ? 'Telegram may have received this upload. Review it before retrying.' : null,
                    ])->save();
                    $operation->touch();

                    return ! $uncertain;
                });

                if ($dispatch) {
                    TelegramOperationJob::dispatch($candidate->id);
                }
            });

        $this->info('Abandoned operations inspected.');

        return self::SUCCESS;
    }
}
