<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Enums\FileOperationStatus;
use App\Domains\Files\Enums\FileOperationType;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Services\TelegramTempStorage;
use Illuminate\Support\Facades\DB;

class CleanupUserStorage
{
    public function __construct(private readonly ManageFileOperation $operations, private readonly PurgeFile $purge, private readonly TelegramTempStorage $temp) {}

    public function handle(User $user, bool $retryFailed = false): bool
    {
        if ($user->deletion_requested_at === null) {
            return false;
        }
        $user->fileOperations()->where('operation', '!=', FileOperationType::Delete)
            ->whereNotIn('status', ['completed', 'cancelled', 'needs_review'])
            ->eachById(function (FileOperation $operation): void {
                if (! empty($operation->metadata['send_started_at']) && empty($operation->metadata['upload_result'])) {
                    if (! $operation->lease_expires_at?->isFuture()) {
                        $operation->update(['status' => FileOperationStatus::NeedsReview, 'error' => 'Remote transfer outcome requires review before account cleanup.']);
                    }

                    return;
                }
                $this->operations->cancel($operation);
            });
        File::withTrashed()->where('user_id', $user->id)->eachById(function (File $file) use ($retryFailed): void {
            $deletion = FileOperation::query()->where('file_id', $file->id)->where('operation', FileOperationType::Delete)->latest('id')->first();
            if ($deletion !== null) {
                if ($deletion->status === FileOperationStatus::Cancelled || ($retryFailed && $deletion->status === FileOperationStatus::Failed)) {
                    $this->operations->retry($deletion);
                }

                return;
            }
            if (FileOperation::query()->where('file_id', $file->id)->whereNotIn('status', ['completed', 'failed', 'cancelled'])->exists()) {
                return;
            }
            $this->purge->handle($file);
        });

        return DB::transaction(function () use ($user): bool {
            $user = User::query()->lockForUpdate()->find($user->id);
            if ($user === null) {
                return true;
            }
            if (File::withTrashed()->where('user_id', $user->id)->exists()
                || $user->fileOperations()->whereNotIn('status', ['completed', 'failed', 'cancelled'])->exists()) {
                return false;
            }
            foreach ($user->fileOperations()->cursor() as $operation) {
                foreach (['temp_path', 'downloaded_path'] as $key) {
                    if (is_string($operation->metadata[$key] ?? null)) {
                        $this->temp->delete($operation->metadata[$key]);
                    }
                }
            }
            TelegramAccount::withTrashed()->where('user_id', $user->id)->where('is_system', false)->eachById(function (TelegramAccount $account) use ($user): void {
                $shared = $account->connections()->where('user_id', '!=', $user->id)->exists()
                    || File::withTrashed()->where('telegram_account_id', $account->id)->where('user_id', '!=', $user->id)->exists();
                if (! $shared) {
                    $account->forceDelete();
                }
            });
            $user->delete();

            return true;
        });
    }
}
