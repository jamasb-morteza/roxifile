<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Enums\FileOperationStatus;
use App\Domains\Files\Enums\FileOperationType;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Runtime\TelegramErrors;
use Illuminate\Support\Facades\Storage;

class ListFileOperations
{
    /**
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    public function handle(User $user, array $ids = []): array
    {
        return array_values(FileOperation::query()->where('user_id', $user->id)->with(['file' => fn ($query) => $query->withTrashed()])
            ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))
            ->orderByRaw("CASE WHEN status IN ('pending', 'retrying', 'processing', 'uploading', 'downloading', 'needs_review') THEN 0 ELSE 1 END")
            ->latest('id')->limit(100)->get()->map(fn (FileOperation $operation): array => [
                'operation_id' => $operation->id,
                'file_id' => $operation->file_id,
                'file_name' => $operation->file->name ?? $operation->metadata['file_name'] ?? $operation->metadata['original_name'] ?? 'Deleted file',
                'operation' => $operation->operation->value,
                'status' => $operation->status->value,
                'progress' => $operation->progress,
                'cancel_requested' => ! empty($operation->metadata['cancel_requested_at']) && in_array($operation->status->value, ['pending', 'retrying', 'processing', 'uploading', 'downloading'], true),
                'can_retry_operation' => $this->canRetryOperation($operation),
                'attempts' => $operation->attempts,
                'error' => $operation->error !== null ? TelegramErrors::message($operation->error) : null,
                'created_at' => $operation->created_at?->toIso8601String(),
                'completed_at' => $operation->completed_at?->toIso8601String(),
            ])->values()->all());
    }

    private function canRetryOperation(FileOperation $operation): bool
    {
        if (! in_array($operation->status, [FileOperationStatus::Failed, FileOperationStatus::Cancelled], true)) {
            return false;
        }

        if ($operation->operation !== FileOperationType::Upload || ! empty($operation->metadata['upload_result'])) {
            return true;
        }

        $tempPath = $operation->metadata['temp_path'] ?? null;

        return is_string($tempPath) && Storage::disk('local')->exists($tempPath);
    }
}
