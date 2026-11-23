<?php

namespace App\Domains\Telegram\Runtime;

use App\Domains\Telegram\Contracts\RoxiLoginSessionClient;
use danog\MadelineProto\API;
use danog\MadelineProto\RPCErrorException;
use Illuminate\Support\Facades\File;
use Throwable;

class MadelineProtoRoxiLoginSessionClient implements RoxiLoginSessionClient
{
    private ?API $api = null;

    public function __construct(private readonly MadelineProtoFactory $factory) {}

    public function isAuthorized(): bool
    {
        return $this->api()->getAuthorization() === API::LOGGED_IN;
    }

    public function requestCode(string $phone): void
    {
        try {
            $this->api()->phoneLogin($phone);
        } catch (Throwable $exception) {
            throw $this->loginException($exception, 'phone');
        }
    }

    public function completeCode(string $code): bool
    {
        try {
            $api = $this->api();
            $api->completePhoneLogin($code);

            return match ($api->getAuthorization()) {
                API::WAITING_PASSWORD => true,
                API::LOGGED_IN => false,
                default => throw new RoxiLoginException('incomplete_login', 'Telegram sign-in did not complete. Start again.'),
            };
        } catch (Throwable $exception) {
            throw $this->loginException($exception, 'code');
        }
    }

    public function completePassword(string $password): void
    {
        try {
            $api = $this->api();
            $api->complete2faLogin($password);

            if ($api->getAuthorization() !== API::LOGGED_IN) {
                throw new RoxiLoginException('incomplete_login', 'Telegram sign-in did not complete. Start again.');
            }
        } catch (Throwable $exception) {
            throw $this->loginException($exception, 'password');
        }
    }

    public function username(): ?string
    {
        try {
            $username = $this->api()->getSelf()['username'] ?? null;

            return is_string($username) ? $username : null;
        } catch (Throwable $exception) {
            throw $this->loginException($exception, 'self');
        }
    }

    public function logout(): void
    {
        try {
            $this->api()->logout();
        } catch (Throwable) {
            // The login flow is cleared even when Telegram has already ended the pending session.
        } finally {
            $this->api = null;
        }
    }

    private function api(): API
    {
        if ($this->api !== null) {
            return $this->api;
        }

        $session = $this->factory->roxiSessionName();
        File::ensureDirectoryExists(dirname($session), 0700);

        return $this->api = $this->factory->make(config('telegram'), $session);
    }

    private function loginException(Throwable $exception, string $stage): RoxiLoginException
    {
        if ($exception instanceof RoxiLoginException) {
            return $exception;
        }

        $reason = strtoupper($exception instanceof RPCErrorException ? $exception->rpc : $exception->getMessage());

        if (str_contains($reason, 'PHONE_CODE_INVALID')) {
            return new RoxiLoginException('invalid_code', 'That Telegram login code is incorrect.', 422, true);
        }

        if (str_contains($reason, 'PHONE_CODE_EXPIRED') || str_contains($reason, 'PHONE_CODE_EMPTY')) {
            return new RoxiLoginException('expired_code', 'That Telegram login code expired. Start again.');
        }

        if (str_contains($reason, 'PASSWORD_HASH_INVALID')) {
            return new RoxiLoginException('invalid_password', 'That Telegram two-step verification password is incorrect.', 422, true);
        }

        if ($stage === 'phone' && str_contains($reason, 'PHONE_NUMBER_INVALID')) {
            return new RoxiLoginException('invalid_phone', 'Telegram rejected that phone number. Check it and try again.');
        }

        return new RoxiLoginException('unavailable', 'Telegram sign-in failed. Start again.', 503);
    }
}
