<?php

namespace App\Domains\Telegram\Runtime;

use Amp\Cancellation;
use App\Domains\Telegram\Contracts\PersonalTelegramSessionClient;
use App\Domains\Telegram\Data\PersonalTelegramUploadData;
use App\Domains\Telegram\Data\TelegramUploadResult;
use App\Domains\Telegram\Enums\PersonalTelegramLoginOutcome;
use danog\MadelineProto\API;
use danog\MadelineProto\RPCErrorException;
use danog\MadelineProto\SecurityException;
use Throwable;

class MadelineProtoPersonalTelegramSessionClient implements PersonalTelegramSessionClient
{
    /** @var array<string, API> */
    private array $sessions = [];

    public function __construct(private readonly MadelineProtoFactory $factory) {}

    public function requestCode(string $sessionName, string $phone): void
    {
        try {
            $this->session($sessionName)->phoneLogin($phone);
        } catch (Throwable $exception) {
            throw $this->loginException($exception, 'phone');
        }
    }

    public function completeCode(string $sessionName, string $code): PersonalTelegramLoginOutcome
    {
        try {
            $api = $this->session($sessionName);
            $api->completePhoneLogin($code);

            return match ($api->getAuthorization()) {
                API::WAITING_PASSWORD => PersonalTelegramLoginOutcome::PasswordRequired,
                API::LOGGED_IN => PersonalTelegramLoginOutcome::Connected,
                default => throw new PersonalTelegramLoginException('failed'),
            };
        } catch (Throwable $exception) {
            throw $this->loginException($exception, 'code');
        }
    }

    public function completePassword(string $sessionName, string $password): void
    {
        try {
            $api = $this->session($sessionName);
            $api->complete2faLogin($password);

            if ($api->getAuthorization() !== API::LOGGED_IN) {
                throw new PersonalTelegramLoginException('failed');
            }
        } catch (Throwable $exception) {
            throw $this->loginException($exception, 'password');
        }
    }

    public function revoke(string $sessionName): void
    {
        try {
            $this->session($sessionName)->logout();
            unset($this->sessions[$sessionName]);
        } catch (Throwable $exception) {
            throw $this->loginException($exception, 'revoke');
        }
    }

    public function upload(string $sessionName, PersonalTelegramUploadData $data, TelegramUploadContent $content, callable $onProgress): TelegramUploadResult
    {
        return $this->withAuthenticatedSession($sessionName, function (API $api) use ($data, $content, $onProgress): TelegramUploadResult {
            $lastReported = 0;
            $onProgressWrapped = function (float $percent) use ($onProgress, $data, &$lastReported): void {
                $bytes = (int) floor(min(100, max(0, $percent)) / 100 * $data->fileSize);
                $now = time();

                if ($now - $lastReported >= 1 || $percent >= 100.0) {
                    $lastReported = $now;
                    $onProgress($bytes, $data->fileSize);
                }
            };

            $uploaded = $api->uploadFromCallable(
                static function (int $offset, int $size, ?Cancellation $cancellation = null) use ($content, $data): string {
                    $cancellation?->throwIfRequested();
                    app(OperationLease::class)->throwIfCancelled($data->operationId);

                    return $content->read(max(1, $size));
                },
                $content->size(),
                $data->mimeType,
                $data->fileName,
                cb: $onProgressWrapped,
                seekable: false,
            );

            $lease = app(OperationLease::class);
            $lease->beginSend($data->operationId);

            try {
                $message = $api->methodCallAsyncRead('messages.sendMedia', [
                    'peer' => ['_' => 'inputPeerSelf'],
                    'media' => [
                        '_' => 'inputMediaUploadedDocument',
                        'file' => $uploaded,
                        'mime_type' => $data->mimeType,
                        'attributes' => [['_' => 'documentAttributeFilename', 'file_name' => $data->fileName]],
                    ],
                    'random_id' => $data->randomId,
                    'message' => $data->caption,
                    'entities' => [],
                ]);
            } catch (RPCErrorException $exception) {
                $lease->updateMetadata($data->operationId, ['send_started_at' => null]);
                throw $exception;
            }

            if (($message['_'] ?? '') !== 'updateShortSentMessage') {
                $updates = $message['updates'] ?? [];
                $message = [];
                foreach ($updates as $update) {
                    if (in_array($update['_'] ?? '', ['updateNewMessage', 'updateNewChannelMessage'], true)) {
                        $message = $update['message'] ?? [];
                        break;
                    }
                }
            }

            $messageId = (int) ($message['id'] ?? 0);
            if ($messageId < 1) {
                throw new \RuntimeException('Telegram did not confirm the uploaded message.');
            }

            $result = new TelegramUploadResult(
                telegramMessageId: $messageId,
                telegramMediaId: isset($message['media']) ? (json_encode($message['media']) ?: null) : null,
            );

            $lease->assertOwned($data->operationId);
            $lease->updateMetadata($data->operationId, [
                'upload_result' => [
                    'message_id' => $result->telegramMessageId,
                    'media_id' => $result->telegramMediaId,
                    'file_id' => $result->telegramFileId,
                ],
            ]);

            return $result;
        });
    }

    public function download(string $sessionName, int $messageId, string $destinationPath): void
    {
        $this->withAuthenticatedSession($sessionName, function (API $api) use ($messageId, $destinationPath): void {
            $message = $this->findMessage($api, $messageId);
            if ($message === null || ! is_array($message['media'] ?? null)) {
                throw new \RuntimeException('The Telegram message for this file is unavailable.');
            }

            $api->downloadToFile($message['media'], $destinationPath);
        });
    }

    public function delete(string $sessionName, int $messageId): void
    {
        $this->withAuthenticatedSession($sessionName, function (API $api) use ($messageId): void {
            if ($this->findMessage($api, $messageId) === null) {
                return;
            }

            $api->methodCallAsyncRead('messages.deleteMessages', ['id' => [$messageId], 'revoke' => true]);
        });
    }

    public function exists(string $sessionName, int $messageId): bool
    {
        return $this->withAuthenticatedSession(
            $sessionName,
            fn (API $api): bool => $this->findMessage($api, $messageId) !== null,
        );
    }

    public function verify(string $sessionName): void
    {
        $this->withAuthenticatedSession($sessionName, static function (API $api): void {
            $api->getInfo('me');
        });
    }

    /** @return array<string, mixed>|null */
    private function findMessage(API $api, int $messageId): ?array
    {
        $result = $api->methodCallAsyncRead('messages.getMessages', ['id' => [$messageId]]);

        foreach ($result['messages'] ?? [] as $message) {
            if ((int) ($message['id'] ?? 0) === $messageId && ($message['_'] ?? '') !== 'messageEmpty') {
                return $message;
            }
        }

        return null;
    }

    /** @template T
     * @param  callable(API): T  $operation
     * @return T
     */
    private function withAuthenticatedSession(string $sessionName, callable $operation): mixed
    {
        try {
            $api = $this->session($sessionName);
            if ($api->getAuthorization() !== API::LOGGED_IN) {
                throw new PersonalTelegramSessionUnavailable;
            }

            return $operation($api);
        } catch (Throwable $exception) {
            if ($exception instanceof PersonalTelegramSessionUnavailable) {
                unset($this->sessions[$sessionName]);
                throw $exception;
            }

            if ($this->isSessionUnavailable($exception)) {
                unset($this->sessions[$sessionName]);

                throw new PersonalTelegramSessionUnavailable($exception);
            }

            throw $exception;
        }
    }

    private function isSessionUnavailable(Throwable $exception): bool
    {
        $reason = strtoupper($exception instanceof RPCErrorException ? $exception->rpc : $exception->getMessage());

        foreach ([
            'SESSION_EXPIRED',
            'SESSION_PASSWORD_NEEDED',
            'AUTH_KEY_UNREGISTERED',
            'AUTH_KEY_INVALID',
            'USER_DEACTIVATED',
            'USER_DEACTIVATED_BAN',
            'UNAUTHORIZED',
            'SESSION_REVOKED',
        ] as $unavailableReason) {
            if (str_contains($reason, $unavailableReason)) {
                return true;
            }
        }

        return $exception instanceof SecurityException;
    }

    private function session(string $sessionName): API
    {
        return $this->sessions[$sessionName] ??= $this->factory->makePersonal(config('telegram'), $sessionName);
    }

    private function loginException(Throwable $exception, string $stage): PersonalTelegramLoginException
    {
        if ($exception instanceof PersonalTelegramLoginException) {
            return $exception;
        }

        $reason = strtoupper($exception instanceof RPCErrorException ? $exception->rpc : $exception->getMessage());

        if (str_contains($reason, 'PHONE_CODE_INVALID')) {
            return new PersonalTelegramLoginException('invalid_code');
        }

        if (str_contains($reason, 'PHONE_CODE_EXPIRED') || str_contains($reason, 'PHONE_CODE_EMPTY')) {
            return new PersonalTelegramLoginException('expired_code');
        }

        if (str_contains($reason, 'PASSWORD_HASH_INVALID')) {
            return new PersonalTelegramLoginException('invalid_password');
        }

        if ($stage === 'phone' && str_contains($reason, 'PHONE_NUMBER_INVALID')) {
            return new PersonalTelegramLoginException('invalid_phone');
        }

        $decision = ErrorClassifier::classify($exception);

        return $decision->retryable
            ? new PersonalTelegramLoginException('temporary', true, $decision->delaySeconds ?? 5)
            : new PersonalTelegramLoginException('failed');
    }
}
