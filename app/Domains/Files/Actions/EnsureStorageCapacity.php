<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Models\TelegramImportRequest;

class EnsureStorageCapacity
{
    public function handle(User $user, int $additionalBytes = 0, int $temporaryBytes = 0): void
    {
        $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
        if ($user->deletion_requested_at !== null && ($additionalBytes > 0 || $temporaryBytes > 0)) {
            throw new \InvalidArgumentException('Account deletion is in progress. New transfers are disabled.');
        }
        $used = (int) File::withTrashed()->where('user_id', $user->id)->where('status', '!=', 'failed')->sum('size');
        $imports = TelegramImportRequest::query()->where('user_id', $user->id)->whereIn('state', ['copying', 'uncertain', 'conflict', 'cancelling']);
        $used += (int) (clone $imports)->sum('size');
        if ($used + $additionalBytes > (int) config('telegram.storage.max_user_bytes', 10 * 1024 ** 3)) {
            throw new \InvalidArgumentException('Your storage limit has been reached. Permanently delete files to free space.');
        }
        $active = FileOperation::query()->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'processing', 'uploading', 'downloading', 'retrying', 'needs_review'])->count();
        $active += $imports->count();
        if ($active >= (int) config('telegram.storage.max_active_operations', 10)) {
            throw new \InvalidArgumentException('Too many transfers are active. Wait for them to finish before starting another.');
        }
        $reserved = (int) FileOperation::query()->where('user_id', $user->id)->sum('reserved_temp_bytes');
        if ($reserved + $temporaryBytes > (int) config('telegram.storage.max_temp_user_bytes', 2 * 1024 ** 3)) {
            throw new \InvalidArgumentException('Your temporary transfer space is full. Wait for active transfers to finish.');
        }
    }
}
