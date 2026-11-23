<?php

namespace App\Domains\Telegram\Actions;

use App\Domains\Files\Actions\AttachTags;
use App\Domains\Files\Actions\EnsureStorageCapacity;
use App\Domains\Files\Enums\FileStatus;
use App\Domains\Files\Enums\FileType;
use App\Domains\Files\Models\File;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Models\TelegramImportRequest;
use App\Domains\Telegram\Runtime\BotApiException;
use App\Domains\Telegram\Runtime\RoxiRuntime;
use App\Domains\Telegram\Services\TelegramBotApi;
use danog\MadelineProto\RPCErrorException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ReceiveTelegramImport
{
    public function __construct(
        private readonly RoxiRuntime $runtime,
        private readonly TelegramBotApi $botApi,
        private readonly EnsureStorageCapacity $capacity,
        private readonly UpdateTelegramImportDetails $details,
    ) {}

    /** @param array<string, mixed> $message */
    public function handleBotUpdate(int $accountId, array $message): void
    {
        $chat = $message['chat'] ?? [];
        $sender = $message['from'] ?? [];
        $chatId = (int) ($chat['id'] ?? 0);
        $senderId = (int) ($sender['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);
        if ($chatId === 0 || $senderId <= 0 || $messageId <= 0 || ($sender['is_bot'] ?? false) || isset($message['sender_chat'])) {
            return;
        }

        $connections = TelegramConnection::query()->where('telegram_account_id', $accountId)
            ->where('method', 'bot')->where('status', TelegramConnectionStatus::Connected)->where('chat_id', $chatId)->get()
            ->filter(fn (TelegramConnection $connection): bool => $connection->target_user_id === $senderId
                || ($connection->target_user_id === null && $connection->chat_type?->value === 'private' && $connection->chat_id === $senderId));
        if ($connections->count() !== 1) {
            return;
        }
        $connection = $connections->sole();
        if ($connection->encryption_mode !== 'none') {
            $this->sayBot($connection->account, $chatId, $messageId, 'Telegram imports are unavailable for encrypted connections.');

            return;
        }

        $replyId = (int) ($message['reply_to_message']['message_id'] ?? 0);
        if ($replyId > 0) {
            $request = TelegramImportRequest::query()->where('telegram_connection_id', $connection->id)
                ->where('chat_id', $chatId)->where('sender_id', $senderId)->where('prompt_message_id', $replyId)->first();
            if ($request !== null) {
                $this->respond($request, trim((string) ($message['text'] ?? '')));

                return;
            }
        }

        $media = $this->botMedia($message);
        if ($media === null) {
            return;
        }
        $request = DB::transaction(function () use ($connection, $message, $media, $senderId, $chatId, $messageId): TelegramImportRequest {
            User::query()->whereKey($connection->user_id)->lockForUpdate()->firstOrFail();

            return TelegramImportRequest::query()->firstOrCreate([
                'telegram_account_id' => $connection->telegram_account_id, 'chat_id' => $chatId,
                'source_message_id' => $messageId,
            ], [
                'telegram_connection_id' => $connection->id, 'user_id' => $connection->user_id, 'sender_id' => $senderId,
                'name' => $this->freeName($connection->user_id, $media['name']),
                'mime_type' => $media['mime_type'], 'size' => $media['size'],
                'source_file_id' => $media['file_id'], 'source_media_id' => $media['media_id'],
                'random_id' => random_int(1, PHP_INT_MAX), 'marker' => 'ROXIFILE-IMPORT-'.Str::uuid(),
                'expires_at' => now()->addDay(), 'source_caption' => (string) ($message['caption'] ?? ''),
            ]);
        });
        $request->refresh();
        if ($request->prompt_message_id === null && $request->state === 'pending') {
            $this->prompt($request);
        }
    }

    /** @param array<string, mixed> $message */
    public function handle(array $message): void
    {
        $sender = $this->sender($message);
        $chat = $this->chat($message);
        if ($sender === null || $chat === null || ($message['out'] ?? false) || ($message['post'] ?? false)) {
            return;
        }
        $connections = TelegramConnection::query()->whereIn('method', ['roxi', 'invite'])
            ->where('status', TelegramConnectionStatus::Connected)->where('chat_id', $chat)
            ->whereHas('account', fn ($query) => $query->where('is_system', true)->where('method', 'roxi'))
            ->where(function ($query) use ($sender): void {
                $query->where('target_user_id', $sender)->orWhere(function ($legacy) use ($sender): void {
                    $legacy->where('method', 'roxi')->whereNull('target_user_id')->where('chat_id', $sender)->where('chat_type', 'private');
                });
            })->get();
        if ($connections->count() !== 1) {
            return;
        }
        $connection = $connections->sole();
        if ($connection->encryption_mode !== 'none') {
            $this->say($chat, (int) ($message['id'] ?? 0), 'Telegram imports are unavailable for encrypted connections.');

            return;
        }
        $replyId = (int) ($message['reply_to']['reply_to_msg_id'] ?? 0);
        if ($replyId > 0) {
            $request = TelegramImportRequest::query()->where('telegram_connection_id', $connection->id)
                ->where('chat_id', $chat)->where('sender_id', $sender)->where('prompt_message_id', $replyId)->first();
            if ($request !== null) {
                $this->respond($request, trim((string) ($message['message'] ?? '')));

                return;
            }
        }
        $media = $this->document($message);
        if ($media === null || (int) ($message['id'] ?? 0) <= 0) {
            return;
        }
        $request = DB::transaction(function () use ($connection, $message, $media, $sender, $chat): TelegramImportRequest {
            User::query()->whereKey($connection->user_id)->lockForUpdate()->firstOrFail();

            return TelegramImportRequest::query()->firstOrCreate([
                'telegram_account_id' => $connection->telegram_account_id, 'chat_id' => $chat,
                'source_message_id' => (int) $message['id'],
            ], [
                'telegram_connection_id' => $connection->id, 'user_id' => $connection->user_id, 'sender_id' => $sender,
                'name' => $this->freeName($connection->user_id, $this->filename($media)),
                'mime_type' => $media['mime_type'] ?? 'application/octet-stream', 'size' => max(0, (int) ($media['size'] ?? 0)),
                'random_id' => random_int(1, PHP_INT_MAX), 'marker' => 'ROXIFILE-IMPORT-'.Str::uuid(),
                'expires_at' => now()->addDay(), 'source_caption' => (string) ($message['message'] ?? ''),
            ]);
        });
        $request->refresh();
        if ($request->prompt_message_id === null && $request->state === 'pending') {
            $this->prompt($request);
        }
    }

    public function recover(): void
    {
        TelegramImportRequest::query()->whereIn('state', ['pending', 'details', 'failed', 'copying', 'uncertain', 'conflict', 'cancelling'])->chunkById(50, function ($requests): void {
            foreach ($requests as $request) {
                try {
                    $this->connection($request);
                    if ($request->state === 'cancelling') {
                        $this->cancelCopy($request);
                    } elseif (in_array($request->state, ['copying', 'uncertain'], true)) {
                        $this->reconcile($request);
                    } elseif ($request->expires_at->isPast() && $request->managed_message_id === null) {
                        $this->expire($request);
                    } elseif ($request->prompt_message_id === null) {
                        $this->prompt($request);
                    }
                } catch (Throwable) {
                    // Durable requests remain available for the next worker tick.
                }
            }
        });
    }

    private function respond(TelegramImportRequest $request, string $response): void
    {
        $command = strtolower($response);
        if ($request->state === 'completed' || $request->state === 'cancelled') {
            return;
        }
        if (in_array($request->state, ['copying', 'uncertain'], true)) {
            $this->reconcile($request);

            return;
        }
        if ($request->state === 'cancelling') {
            $this->cancelCopy($request);

            return;
        }
        if ($request->state === 'conflict') {
            if ($command === 'cancel') {
                TelegramImportRequest::query()->whereKey($request->id)->where('state', 'conflict')->update(['state' => 'cancelling']);
                $this->cancelCopy($request->refresh());
            } elseif ($command === 'details' || (isset($request->details['stage']) && $command !== 'root')) {
                if ($this->details->handle($request, $response)) {
                    $this->prompt($request);
                } else {
                    $this->finish($request);
                }
            } elseif (in_array($command, ['root', 'confirm'], true)) {
                $this->finish($request);
            } else {
                $this->prompt($request);
            }

            return;
        }
        if ($request->expires_at->isPast() || $request->state === 'expired') {
            if ($command === 'restart') {
                $request->update(['state' => 'pending', 'expires_at' => now()->addDay(), 'last_error' => null, 'details' => null, 'prompt_message_id' => null]);
                $this->prompt($request);
            } else {
                $this->expire($request);
            }

            return;
        }
        if ($command === 'cancel') {
            $cancelled = TelegramImportRequest::query()->whereKey($request->id)->whereIn('state', ['pending', 'details', 'failed'])->update(['state' => 'cancelled']);
            if ($cancelled === 0) {
                return;
            }
            $this->say($request->chat_id, $request->source_message_id, 'Import cancelled. The original message is preserved.', $request);

            return;
        }
        if ($command === 'root') {
            $request->update(['details' => null]);
        } elseif ($command === 'details' || $request->state === 'details') {
            if ($this->details->handle($request, $response)) {
                $this->prompt($request);

                return;
            }
            $command = 'retry';
        }
        if (! in_array($command, ['root', 'retry'], true)) {
            $this->prompt($request);

            return;
        }
        try {
            $source = $this->source($request);
            $reserved = DB::transaction(function () use ($request, $source): bool {
                User::query()->whereKey($request->user_id)->lockForUpdate()->firstOrFail();
                $locked = TelegramImportRequest::query()->lockForUpdate()->findOrFail($request->id);
                if (! in_array($locked->state, ['pending', 'details', 'failed'], true)) {
                    return false;
                }
                $connection = $this->connection($locked);
                if (! $this->details->validateDestination($locked)) {
                    return false;
                }
                $name = $this->freeName($locked->user_id, $locked->name, $locked->id, $locked->details['directory_id'] ?? null);
                if ($name !== $locked->name) {
                    $locked->update(['name' => $name, 'state' => $locked->details === null ? 'pending' : 'details', 'prompt_message_id' => null]);

                    return false;
                }
                $media = $this->document($source);
                if ($media === null) {
                    throw new \RuntimeException('The source media is missing. Send the file again to restart.');
                }
                $this->capacity->handle($connection->user, max(0, (int) ($media['size'] ?? 0)));
                if ($connection->encryption_locked_at === null) {
                    $connection->update(['encryption_locked_at' => now()]);
                }
                $locked->update(['state' => 'copying', 'size' => max(0, (int) ($media['size'] ?? 0)),
                    'mime_type' => $media['mime_type'] ?? 'application/octet-stream', 'last_error' => null]);

                return true;
            });
            $request->refresh();
            if (! $reserved) {
                if (in_array($request->state, ['pending', 'details'], true)) {
                    $this->prompt($request);
                }

                return;
            }
            try {
                if ($this->connection($request)->method->value === 'bot') {
                    $response = $this->botApi->call($this->importToken($request), 'copyMessage', [
                        'chat_id' => $request->chat_id,
                        'from_chat_id' => $request->chat_id,
                        'message_id' => $request->source_message_id,
                        'caption' => $this->caption($request),
                    ]);
                    $id = (int) $response->json('result.message_id', 0);
                } else {
                    $document = $this->document($source);
                    $result = $this->runtime->methodCallAsyncRead('messages.sendMedia', [
                        'peer' => $request->chat_id, 'media' => ['_' => ($document['_'] === 'photo' ? 'inputMediaPhoto' : 'inputMediaDocument'), 'id' => [
                            '_' => ($document['_'] === 'photo' ? 'inputPhoto' : 'inputDocument'), 'id' => $document['id'], 'access_hash' => $document['access_hash'],
                            'file_reference' => $document['file_reference'] ?? '',
                        ]], 'random_id' => $request->random_id, 'message' => $this->caption($request),
                    ]);
                    $id = $this->sentId($result);
                }
                if ($id <= 0) {
                    throw new \RuntimeException('Telegram did not confirm the managed copy.');
                }
                $request->update(['managed_message_id' => $id]);
                $this->finish($request);
            } catch (BotApiException $error) {
                $state = $error->httpStatus === 0 || $error->httpStatus >= 500 ? 'uncertain' : 'failed';
                $request->update(['state' => $state, 'last_error' => $state === 'uncertain'
                    ? 'The Bot API copy response was lost. Telegram does not let bots search sent messages, so reply retry will not send another copy. Send the source file again to start a new request after checking the chat.'
                    : 'Telegram rejected the Bot API copy. Check that the source message and chat are still available, then reply retry.']);
                $this->say($request->chat_id, $request->source_message_id, (string) $request->last_error, $request);
            } catch (RPCErrorException $error) {
                $request->update(['state' => 'failed', 'last_error' => 'Telegram rejected the copy. Check media access and reply retry.']);
                $this->say($request->chat_id, $request->source_message_id, (string) $request->last_error, $request);
            } catch (Throwable) {
                $error = $this->connection($request)->method->value === 'bot'
                    ? 'The Bot API copy outcome is uncertain. Reply retry to finish registration; RoxiFile will not send another copy when it cannot confirm one.'
                    : 'Copy outcome is uncertain. Reply retry to reconcile before another send.';
                $request->update(['state' => 'uncertain', 'last_error' => $error]);
                $this->say($request->chat_id, $request->source_message_id, (string) $request->last_error, $request);
            }
        } catch (Throwable $error) {
            $request->update(['state' => 'failed', 'last_error' => $error instanceof \InvalidArgumentException ? $error->getMessage() : 'Source unavailable or connection ownership changed. Check access and reply retry.']);
            $this->say($request->chat_id, $request->source_message_id, (string) $request->last_error, $request);
        }
    }

    private function cancelCopy(TelegramImportRequest $request): void
    {
        if ($request->state !== 'cancelling') {
            return;
        }
        $this->connection($request);
        if ($request->managed_message_id === null || $request->managed_message_id === $request->source_message_id) {
            throw new \RuntimeException('The managed copy cannot be safely identified for cancellation.');
        }
        try {
            if ($this->connection($request)->method->value === 'bot') {
                $this->botApi->call($this->importToken($request), 'deleteMessage', [
                    'chat_id' => $request->chat_id, 'message_id' => $request->managed_message_id,
                ]);
            } else {
                $this->runtime->deleteMessages($request->chat_id, [$request->managed_message_id]);
            }
        } catch (Throwable) {
            $request->update(['last_error' => 'Cancellation cleanup is pending. Reply retry to confirm removal of the managed copy.']);
            $this->say($request->chat_id, $request->source_message_id, (string) $request->last_error, $request);

            return;
        }
        $request->update(['state' => 'cancelled', 'last_error' => null]);
        $this->say($request->chat_id, $request->source_message_id, 'Import cancelled and managed copy removed. The original message is preserved.', $request);
    }

    private function reconcile(TelegramImportRequest $request): void
    {
        $this->connection($request);
        if ($request->managed_message_id !== null) {
            $this->finish($request);

            return;
        }
        if ($this->connection($request)->method->value === 'bot') {
            $request->update(['state' => 'uncertain', 'last_error' => 'The Bot API cannot search sent messages to reconcile this copy. Do not retry it; check the chat, then send the source file again to start a new request.']);
            $this->say($request->chat_id, $request->source_message_id, (string) $request->last_error, $request);

            return;
        }
        $result = $this->runtime->methodCallAsyncRead('messages.search', [
            'peer' => $request->chat_id, 'q' => $request->marker, 'filter' => ['_' => 'inputMessagesFilterEmpty'], 'limit' => 100,
        ]);
        foreach ($result['messages'] ?? [] as $message) {
            if (($message['out'] ?? false) && ($message['message'] ?? '') === $this->caption($request) && $this->document($message) !== null) {
                $request->update(['managed_message_id' => (int) $message['id']]);
                $this->finish($request);

                return;
            }
        }
        $request->update(['state' => 'uncertain', 'last_error' => 'Managed copy not confirmed yet. Reply retry to check again; no duplicate will be sent.']);
        $this->say($request->chat_id, $request->source_message_id, (string) $request->last_error, $request);
    }

    private function finish(TelegramImportRequest $request): void
    {
        $completed = DB::transaction(function () use ($request): bool {
            User::query()->whereKey($request->user_id)->lockForUpdate()->firstOrFail();
            $locked = TelegramImportRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->file_id !== null) {
                $locked->update(['state' => 'completed', 'last_error' => null]);

                return true;
            }
            $this->connection($locked);
            if (! in_array($locked->state, ['copying', 'uncertain', 'conflict'], true)) {
                throw new \RuntimeException('This import is no longer awaiting registration.');
            }
            if (! $this->details->validateDestination($locked)) {
                return false;
            }
            $name = $this->freeName($locked->user_id, $locked->name, $locked->id, $locked->details['directory_id'] ?? null);
            if ($name !== $locked->name) {
                $locked->update(['state' => 'conflict', 'name' => $name, 'prompt_message_id' => null]);

                return false;
            }
            $file = File::query()->create([
                'directory_id' => $locked->details['directory_id'] ?? null, 'description' => $locked->details['description'] ?? null,
                'user_id' => $locked->user_id, 'telegram_account_id' => $locked->telegram_account_id,
                'telegram_connection_id' => $locked->telegram_connection_id, 'telegram_chat_id' => $locked->chat_id,
                'telegram_message_id' => $locked->managed_message_id, 'name' => $locked->name, 'original_name' => $locked->name,
                'telegram_media_id' => $locked->source_media_id, 'telegram_file_id' => $locked->source_file_id,
                'extension' => mb_substr(pathinfo($locked->name, PATHINFO_EXTENSION), 0, 16) ?: null,
                'mime_type' => $locked->mime_type, 'file_type' => FileType::fromMime($locked->mime_type),
                'size' => $locked->size, 'status' => FileStatus::Uploaded,
                'metadata' => ['telegram_source_caption' => (string) $locked->getAttribute('source_caption'),
                    'telegram_import_marker' => $locked->marker],
            ]);
            app(AttachTags::class)->handle($file->user, $file, $locked->details['tags'] ?? []);
            $locked->update(['state' => 'completed', 'file_id' => $file->id, 'last_error' => null]);

            return true;
        });
        if (! $completed) {
            $this->prompt($request->refresh());

            return;
        }
        try {
            $this->say($request->chat_id, $request->source_message_id, 'Saved /'.trim(($request->details['directory_path'] ?? '').'/'.$request->name, '/').'. The original message is preserved.', $request);
        } catch (Throwable) {
            return;
        }
    }

    private function connection(TelegramImportRequest $request): TelegramConnection
    {
        $connection = TelegramConnection::query()->lockForUpdate()->findOrFail($request->telegram_connection_id);
        if ($connection->user_id !== $request->user_id || $connection->telegram_account_id !== $request->telegram_account_id
            || $connection->chat_id !== $request->chat_id
            || ($connection->target_user_id !== $request->sender_id && ! (in_array($connection->method->value, ['roxi', 'bot'], true)
                && $connection->target_user_id === null && $connection->chat_id === $request->sender_id
                && $connection->chat_type?->value === 'private'))
            || $connection->status !== TelegramConnectionStatus::Connected || $connection->encryption_mode !== 'none') {
            throw new \RuntimeException('Connection ownership or encryption changed.');
        }

        return $connection;
    }

    /** @return array<string, mixed> */
    private function source(TelegramImportRequest $request): array
    {
        $connection = $this->connection($request);
        if ($connection->method->value === 'bot') {
            if ($request->source_file_id === null) {
                throw new \RuntimeException('The source media is unavailable. Send the file again to restart.');
            }

            return ['id' => $request->source_message_id, 'bot_document' => [
                '_' => 'document', 'id' => 0, 'file_id' => $request->source_file_id,
                'file_unique_id' => $request->source_media_id, 'mime_type' => $request->mime_type,
                'size' => $request->size, 'attributes' => [['_' => 'documentAttributeFilename', 'file_name' => $request->name]],
            ]];
        }
        $result = $this->runtime->getMessages($request->chat_id, [$request->source_message_id]);
        foreach ($result['messages'] ?? [] as $message) {
            if (($message['id'] ?? null) === $request->source_message_id && $this->sender($message) === $request->sender_id
                && ! ($message['out'] ?? false) && ! ($message['post'] ?? false) && $this->document($message) !== null
                && $this->chat($message) === $request->chat_id) {
                return $message;
            }
        }
        throw new \RuntimeException('The source message or media is unavailable.');
    }

    private function caption(TelegramImportRequest $request): string
    {
        $original = trim((string) $request->getAttribute('source_caption'));

        return ($original === '' ? '' : mb_substr($original, 0, 800)."\n\n").$request->marker;
    }

    private function prompt(TelegramImportRequest $request): void
    {
        $text = $this->details->prompt($request) ?? ($request->state === 'conflict'
            ? 'The destination name changed to '.$request->name.'. Reply confirm to save, details to edit, or cancel.'
            : 'Save '.$request->name.' at root? Reply root, details or cancel to this prompt. Expires in 24 hours.');
        $parts = mb_str_split($text, 1500);
        $request->update(['prompt_message_id' => null]);
        foreach ($parts as $index => $part) {
            $label = count($parts) > 1 ? 'Import #'.$request->id.' ('.($index + 1).'/'.count($parts)."). Reply to the final part.\n" : '';
            $result = $this->say($request->chat_id, $request->source_message_id, $label.$part, $request);
            $id = $this->sentId($result);
            if ($id <= 0) {
                throw new \RuntimeException('Telegram did not confirm the import prompt.');
            }
            if ($index === array_key_last($parts)) {
                $request->update(['prompt_message_id' => $id]);
            }
        }
    }

    private function expire(TelegramImportRequest $request): void
    {
        $expired = TelegramImportRequest::query()->whereKey($request->id)->whereIn('state', ['pending', 'details', 'failed', 'expired'])->update(['state' => 'expired']);
        if ($expired === 0) {
            return;
        }
        $this->say($request->chat_id, (int) ($request->prompt_message_id ?? $request->source_message_id),
            'Import expired. Reply restart to the original prompt to begin again. The original is preserved.', $request);
    }

    /** @return array<string, mixed> */
    private function say(int $chat, int $reply, string $text, ?TelegramImportRequest $request = null): array
    {
        if ($request !== null && $this->connection($request)->method->value === 'bot') {
            $result = $this->botApi->call($this->importToken($request), 'sendMessage', [
                'chat_id' => $chat, 'text' => $text, 'reply_to_message_id' => $reply,
            ])->json('result', []);

            return is_array($result) ? $result : [];
        }

        return $this->runtime->methodCallAsyncRead('messages.sendMessage', [
            'peer' => $chat, 'message' => $text, 'random_id' => random_int(1, PHP_INT_MAX),
            'reply_to' => ['_' => 'inputReplyToMessage', 'reply_to_msg_id' => $reply],
        ]);
    }

    private function sayBot(TelegramAccount $account, int $chat, int $reply, string $text): void
    {
        $this->botApi->call($account->credentials ?? throw new \RuntimeException('Bot credentials are not configured.'), 'sendMessage', [
            'chat_id' => $chat, 'text' => $text, 'reply_to_message_id' => $reply,
        ]);
    }

    private function importAccount(TelegramImportRequest $request): TelegramAccount
    {
        return TelegramAccount::query()->findOrFail($request->telegram_account_id);
    }

    private function importToken(TelegramImportRequest $request): string
    {
        return $this->importAccount($request)->credentials ?? throw new \RuntimeException('Bot credentials are not configured.');
    }

    /** @param array<string, mixed> $message
     * @return array{name: string, mime_type: string, size: int, file_id: string, media_id: string|null}|null
     */
    private function botMedia(array $message): ?array
    {
        $document = $message['document'] ?? null;
        if (is_array($document) && is_string($document['file_id'] ?? null)) {
            $name = basename(str_replace('\\', '/', (string) ($document['file_name'] ?? '')));
            $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '';

            return [
                'name' => $name !== '' && $name !== '.' && $name !== '..' ? mb_substr($name, 0, 220) : 'telegram-file-'.($document['file_unique_id'] ?? $document['file_id']),
                'mime_type' => (string) ($document['mime_type'] ?? 'application/octet-stream'),
                'size' => max(0, (int) ($document['file_size'] ?? 0)),
                'file_id' => $document['file_id'],
                'media_id' => is_string($document['file_unique_id'] ?? null) ? $document['file_unique_id'] : null,
            ];
        }

        $photos = $message['photo'] ?? null;
        if (! is_array($photos) || $photos === []) {
            return null;
        }
        $photo = end($photos);
        if (! is_array($photo) || ! is_string($photo['file_id'] ?? null)) {
            return null;
        }
        $uniqueId = (string) ($photo['file_unique_id'] ?? $photo['file_id']);

        return [
            'name' => 'telegram-photo-'.$uniqueId.'.jpg', 'mime_type' => 'image/jpeg',
            'size' => max(0, (int) ($photo['file_size'] ?? 0)), 'file_id' => $photo['file_id'],
            'media_id' => is_string($photo['file_unique_id'] ?? null) ? $photo['file_unique_id'] : null,
        ];
    }

    /** @param array<string, mixed> $message */
    private function sender(array $message): ?int
    {
        return ($message['from_id']['_'] ?? '') === 'peerUser' && ! isset($message['sender_chat'])
            && (int) ($message['from_id']['user_id'] ?? 0) > 0 ? (int) $message['from_id']['user_id'] : null;
    }

    /** @param array<string, mixed> $message */
    private function chat(array $message): ?int
    {
        $peer = $message['peer_id'] ?? [];

        return match ($peer['_'] ?? '') {
            'peerChat' => -(int) $peer['chat_id'],
            'peerChannel' => -1000000000000 - (int) $peer['channel_id'],
            'peerUser' => $this->sender($message),
            default => null,
        };
    }

    /** @param array<string, mixed> $message
     * @return array<string, mixed>|null
     */
    private function document(array $message): ?array
    {
        if (is_array($message['bot_document'] ?? null)) {
            return $message['bot_document'];
        }
        $photo = $message['media']['photo'] ?? null;
        if (is_array($photo) && ($photo['_'] ?? '') === 'photo') {
            $photo['mime_type'] = 'image/jpeg';
            $photo['size'] = 0;
            foreach ($photo['sizes'] ?? [] as $size) {
                $photo['size'] = max($photo['size'], (int) ($size['size'] ?? 0), ...($size['sizes'] ?? [0]));
            }

            return $photo;
        }
        $document = $message['media']['document'] ?? null;

        return is_array($document) && ($document['_'] ?? '') === 'document' ? $document : null;
    }

    /** @param array<string, mixed> $document */
    private function filename(array $document): string
    {
        foreach ($document['attributes'] ?? [] as $attribute) {
            if (($attribute['_'] ?? '') === 'documentAttributeFilename') {
                $name = basename(str_replace('\\', '/', (string) ($attribute['file_name'] ?? '')));
                $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '';
                if ($name !== '' && $name !== '.' && $name !== '..') {
                    return mb_substr($name, 0, 220);
                }
            }
        }

        return ($document['_'] === 'photo' ? 'telegram-photo-' : 'telegram-file-').$document['id'].($document['_'] === 'photo' ? '.jpg' : '');
    }

    private function freeName(int $userId, string $name, ?int $except = null, ?int $directoryId = null): string
    {
        $candidate = $name;
        $counter = 1;
        while (File::query()->where('user_id', $userId)->where('directory_id', $directoryId)->where('name', $candidate)->exists()
            || TelegramImportRequest::query()->where('user_id', $userId)->where('name', $candidate)->where('details->directory_id', $directoryId)
                ->whereIn('state', ['copying', 'uncertain', 'conflict', 'cancelling'])->when($except !== null, fn ($query) => $query->where('id', '!=', $except))->exists()) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $candidate = pathinfo($name, PATHINFO_FILENAME).' ('.$counter++.')'.($extension !== '' ? '.'.$extension : '');
        }

        return $candidate;
    }

    /** @param array<string, mixed> $result */
    private function sentId(array $result): int
    {
        if (isset($result['message_id'])) {
            return (int) $result['message_id'];
        }
        if (($result['_'] ?? '') === 'updateShortSentMessage') {
            return (int) ($result['id'] ?? 0);
        }
        foreach ($result['updates'] ?? [] as $update) {
            if (in_array($update['_'] ?? '', ['updateNewMessage', 'updateNewChannelMessage'], true)) {
                return (int) ($update['message']['id'] ?? 0);
            }
        }

        return 0;
    }
}
