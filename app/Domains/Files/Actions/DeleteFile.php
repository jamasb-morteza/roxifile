<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Enums\FileOperationStatus;
use App\Domains\Files\Enums\FileOperationType;
use App\Domains\Files\Enums\FileStatus;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Telegram\Jobs\TelegramOperationJob;
use Illuminate\Support\Facades\DB;

class DeleteFile
{
    public function handle(File $file, bool $permanent = false): ?FileOperation
    {
        if (! $permanent) {
            if (FileOperation::query()->where('file_id', $file->id)->whereIn('operation', ['upload', 'copy'])
                ->whereNotIn('status', ['completed', 'cancelled', 'failed'])->exists()) {
                throw new \InvalidArgumentException('Finish or cancel the transfer before moving this file to Trash.');
            }
            $file->delete();

            return null;
        }

        $operation = DB::transaction(function () use ($file): ?FileOperation {
            $file = File::withTrashed()->lockForUpdate()->findOrFail($file->id);
            if (FileOperation::query()->where('user_id', $file->user_id)->where('operation', FileOperationType::Copy)
                ->where('metadata->source_file_id', $file->id)->whereNotIn('status', ['completed', 'cancelled', 'failed'])->exists()) {
                throw new \InvalidArgumentException('Wait for copies of this file to finish before permanently deleting it.');
            }
            $active = FileOperation::query()->where('file_id', $file->id)
                ->whereNotIn('status', ['completed', 'cancelled', 'failed'])->first();

            if ($active !== null) {
                if ($active->operation === FileOperationType::Delete) {
                    return $active;
                }

                throw new \InvalidArgumentException('Wait for the active transfer to finish before permanently deleting this file.');
            }

            $file->delete();

            if ($file->telegram_message_id === null) {
                if (FileOperation::query()->where('file_id', $file->id)->where(fn ($query) => $query->whereNotNull('metadata->upload_result')->orWhereNotNull('metadata->send_started_at'))->exists()) {
                    throw new \InvalidArgumentException('Recover or review the saved remote upload result before permanently deleting this file.');
                }
                $file->forceDelete();

                return null;
            }

            $file->update(['status' => FileStatus::Deleting]);

            return $file->user->fileOperations()->create([
                'file_id' => $file->id,
                'telegram_account_id' => $file->telegram_account_id,
                'telegram_connection_id' => $file->telegram_connection_id,
                'operation' => FileOperationType::Delete,
                'status' => FileOperationStatus::Pending,
                'metadata' => ['permanent' => true, 'file_name' => $file->name],
            ]);
        });

        if ($operation !== null) {
            TelegramOperationJob::dispatch($operation->id);
            $operation->refresh();
        }

        return $operation;
    }
}
