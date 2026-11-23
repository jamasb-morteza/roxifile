<?php

namespace App\Domains\Telegram\Services;

use App\Domains\Files\Enums\FileOperationStatus;
use App\Domains\Files\Enums\FileOperationType;
use App\Domains\Files\Enums\FileStatus;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Telegram\Runtime\BotApiException;
use App\Domains\Telegram\Runtime\OperationLease;
use App\Domains\Telegram\Runtime\RoxiRuntime;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Serialize remote writes per file without holding a database transaction
 * across asynchronous Telegram I/O. Unknown Bot sends retain a durable guard;
 * MTProto retries reuse the same random_id.
 */
class SyncTelegramMetadata
{
    public function __construct(private readonly TelegramMetadataProjection $projection, private readonly TelegramBotApi $bot, private readonly RoxiRuntime $roxi, private readonly OperationLease $lease) {}

    public function handle(FileOperation $operation): void
    {
        $lock = Cache::lock('telegram:metadata:'.$operation->file_id, 600);
        if (! $lock->get()) {
            throw new BotApiException('Metadata synchronization is busy. Please retry.', true, 5);
        }
        try {
            $this->project($operation);
        } finally {
            $lock->release();
        }
    }

    private function project(FileOperation $operation): void
    {
        $companion = $this->editCaption($operation);
        if ($companion) {
            $this->companion($operation);
        }
    }

    private function editCaption(FileOperation $operation): bool
    {
        $file = $this->currentFile($operation);
        if ($this->superseded($operation)) {
            return false;
        }
        $this->authorizeProjection($file);
        if (! empty($file->metadata['telegram_metadata_companion_id']) || ! empty($file->metadata['telegram_metadata_companion_pending'])) {
            return true;
        }
        if (! array_key_exists('telegram_source_caption', $file->metadata ?? []) && ! ($file->metadata['telegram_metadata_owned_caption'] ?? false)) {
            return true;
        }
        $source = $file->connection?->encryption_mode === 'contents_and_metadata'
            ? ''
            : (string) ($file->metadata['telegram_source_caption'] ?? '');
        $prefix = $source === '' ? '' : "User caption:\n".$source."\n\nRoxiFile metadata\n";
        $budget = 1024 - $this->length($prefix);
        if ($budget < 256) {
            return true;
        }
        try {
            $this->edit($file, $file->telegram_message_id ?? 0, $prefix.$this->projection->caption($file, $budget), false);
        } catch (Throwable $error) {
            if (! $this->cannotEdit($error)) {
                throw $error;
            }

            return true;
        }

        return false;
    }

    private function companion(FileOperation $operation): void
    {
        $recoveringSend = false;
        $sendAllowed = DB::transaction(function () use ($operation, &$recoveringSend): bool {
            $file = $this->lockedFile($operation);
            if ($this->superseded($operation)) {
                return false;
            }
            $this->authorizeProjection($file);
            if (! empty($file->metadata['telegram_metadata_companion_id'])) {
                return true;
            }
            $recoveringSend = ! empty($file->metadata['telegram_metadata_companion_pending']);
            if ($recoveringSend && $file->connection?->method->value === 'bot') {
                $this->lease->mutate($operation->id, function (FileOperation $current): void {
                    $current->update([
                        'status' => FileOperationStatus::NeedsReview,
                        'error' => 'Telegram may have received the companion message. Reconcile its identity before retrying.',
                    ]);
                });

                return false;
            }
            $this->saveMetadata($file, [
                'telegram_metadata_companion_pending' => true,
                'telegram_metadata_random_id' => $file->metadata['telegram_metadata_random_id'] ?? random_int(1, PHP_INT_MAX),
            ]);

            return true;
        });
        if (! $sendAllowed) {
            return;
        }
        try {
            $file = $this->currentFile($operation);
            if ($this->superseded($operation)) {
                if (! $recoveringSend) {
                    $this->saveMetadata($file, ['telegram_metadata_companion_pending' => false]);
                }

                return;
            }
            $this->authorizeProjection($file);
            $body = $this->companionBody($file);
            $id = (int) ($file->metadata['telegram_metadata_companion_id'] ?? 0);
            if ($id === 0 && $recoveringSend) {
                $id = $this->recoverCompanion($file) ?? 0;
                if ($id > 0) {
                    $this->saveMetadata($file, ['telegram_metadata_companion_id' => $id, 'telegram_metadata_companion_pending' => false]);
                }
            }
            if ($id > 0) {
                $current = $this->currentFile($operation);
                $this->authorizeProjection($current);
                $this->edit($current, $id, $this->companionBody($current), true);

                return;
            }
            $this->lease->refresh();
            $this->lease->assertOwned($operation->id);
            if ($file->connection?->method->value === 'bot') {
                $result = $this->bot->call($file->account->credentials ?? '', 'sendMessage', [
                    'chat_id' => $file->telegram_chat_id ?? $file->connection->chat_id,
                    'text' => $body, 'reply_parameters' => ['message_id' => $file->telegram_message_id],
                    'link_preview_options' => ['is_disabled' => true],
                ])->json('result', []);
                $id = (int) ($result['message_id'] ?? 0);
            } else {
                try {
                    $result = $this->roxi->methodCallAsyncRead('messages.sendMessage', [
                        'peer' => $file->telegram_chat_id ?? $file->connection?->chat_id,
                        'message' => $body, 'entities' => [], 'no_webpage' => true,
                        'random_id' => $file->metadata['telegram_metadata_random_id'],
                        'reply_to' => ['_' => 'inputReplyToMessage', 'reply_to_msg_id' => $file->telegram_message_id],
                    ]);
                } catch (Throwable $error) {
                    if (! str_contains(strtoupper($error->getMessage()), 'RANDOM_ID_DUPLICATE')) {
                        throw $error;
                    }
                    $id = $this->recoverCompanion($file) ?? 0;
                    if ($id <= 0) {
                        $this->lease->mutate($operation->id, function (FileOperation $current): void {
                            $current->update([
                                'status' => FileOperationStatus::NeedsReview,
                                'error' => 'Telegram accepted a companion, but its identity could not be recovered. Reconcile before retrying.',
                            ]);
                        });

                        return;
                    }
                    $result = ['id' => $id];
                    $recoveringSend = true;
                }
                $id = (int) ($result['id'] ?? 0);
                foreach ($result['updates'] ?? [] as $update) {
                    if (($update['_'] ?? '') === 'updateMessageID') {
                        $id = (int) $update['id'];
                    }
                }
            }
            if ($id <= 0) {
                throw new \RuntimeException('Telegram returned no companion message identity. Reconcile before retrying.');
            }
            $this->saveMetadata($file, ['telegram_metadata_companion_id' => $id, 'telegram_metadata_companion_pending' => false]);
            if ($recoveringSend) {
                $current = $this->currentFile($operation);
                $this->authorizeProjection($current);
                $this->edit($current, $id, $this->companionBody($current), true);
            }
        } catch (Throwable $error) {
            if ($error instanceof BotApiException && $error->httpStatus > 0 && $error->httpStatus < 500) {
                DB::transaction(function () use ($operation): void {
                    $this->saveMetadata($this->lockedFile($operation), ['telegram_metadata_companion_pending' => false]);
                });
            }
            throw $error;
        }
    }

    private function companionBody(File $file): string
    {
        $marker = $this->companionMarker($file);

        return "RoxiFile metadata\n".$this->projection->caption($file, 4096 - 18 - $this->length($marker)).$marker;
    }

    private function companionMarker(File $file): string
    {
        return "\nRoxiFile reference: metadata-".$file->id.'-'.($file->metadata['telegram_metadata_random_id'] ?? 0);
    }

    private function recoverCompanion(File $file): ?int
    {
        $this->lease->refresh();
        $history = $this->roxi->methodCallAsyncRead('messages.getHistory', [
            'peer' => $file->telegram_chat_id ?? $file->connection?->chat_id,
            'offset_id' => 0, 'offset_date' => 0, 'add_offset' => 0,
            'limit' => 100, 'max_id' => 0, 'min_id' => 0, 'hash' => 0,
        ]);
        foreach ($history['messages'] ?? [] as $message) {
            if (($message['out'] ?? false) === true && (int) ($message['id'] ?? 0) > 0
                && (int) ($message['reply_to']['reply_to_msg_id'] ?? 0) === $file->telegram_message_id
                && str_ends_with((string) ($message['message'] ?? ''), $this->companionMarker($file))) {
                return (int) $message['id'];
            }
        }

        return null;
    }

    private function edit(File $file, int $id, string $body, bool $text): void
    {
        $this->lease->refresh();
        try {
            if ($file->connection?->method->value === 'bot') {
                $this->bot->call($file->account->credentials ?? '', $text ? 'editMessageText' : 'editMessageCaption', [
                    'chat_id' => $file->telegram_chat_id ?? $file->connection->chat_id,
                    'message_id' => $id, $text ? 'text' : 'caption' => $body,
                ]);
            } else {
                $this->roxi->methodCallAsyncRead('messages.editMessage', [
                    'peer' => $file->telegram_chat_id ?? $file->connection?->chat_id,
                    'id' => $id, 'message' => $body, 'entities' => [], 'no_webpage' => true,
                ]);
            }
        } catch (Throwable $error) {
            if (! str_contains(strtolower($error->getMessage()), 'message is not modified') && ! str_contains($error->getMessage(), 'MESSAGE_NOT_MODIFIED')) {
                throw $error;
            }
        }
    }

    private function currentFile(FileOperation $operation): File
    {
        return File::query()->with(['connection', 'account', 'directory'])->findOrFail($operation->file_id);
    }

    private function lockedFile(FileOperation $operation): File
    {
        return File::query()->with(['connection', 'account', 'directory'])->lockForUpdate()->findOrFail($operation->file_id);
    }

    private function authorizeProjection(File $file): void
    {
        $mode = $file->connection?->encryption_mode;
        if (! in_array($mode, ['none', 'contents', 'contents_and_metadata'], true)
            || ($mode === 'none' && $file->encryption_header !== null)
            || ($mode !== 'none' && $file->encryption_header === null)) {
            throw new \DomainException('The connection encryption scope does not permit this metadata projection.');
        }
        if ($file->status !== FileStatus::Uploaded || $file->telegram_message_id === null) {
            throw new \DomainException('Only available stored files can synchronize metadata.');
        }
    }

    private function superseded(FileOperation $operation): bool
    {
        return FileOperation::query()->where('file_id', $operation->file_id)->where('operation', FileOperationType::Metadata)->where('id', '>', $operation->id)->exists();
    }

    /** @param array<string, mixed> $changes */
    private function saveMetadata(File $file, array $changes): void
    {
        DB::transaction(function () use ($file, $changes): void {
            $current = File::query()->lockForUpdate()->findOrFail($file->id);
            $current->forceFill(['metadata' => array_merge($current->metadata ?? [], $changes)])->save();
        });
    }

    private function length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    private function cannotEdit(Throwable $error): bool
    {
        $message = strtolower($error->getMessage());
        foreach (["message can't be edited", 'message can not be edited', 'message_author_required', 'message_edit_time_expired', 'chat_admin_required', 'not enough rights to edit', 'message to edit not found'] as $reason) {
            if (str_contains($message, $reason)) {
                return true;
            }
        }

        return false;
    }
}
