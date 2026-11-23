<?php

namespace App\Domains\Telegram\Actions;

use App\Domains\Telegram\Contracts\RoxiLoginSessionClient;
use App\Domains\Telegram\Data\CompleteRoxiLoginCodeData;
use App\Domains\Telegram\Data\CompleteRoxiLoginPasswordData;
use App\Domains\Telegram\Data\StartRoxiLoginData;
use App\Domains\Telegram\Enums\RoxiLoginStep;
use App\Domains\Telegram\Runtime\RoxiLoginException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class AuthenticateRoxiTelegramAccount
{
    public const SESSION_STEP_KEY = 'telegram.roxi_login.step';

    public const SESSION_LOCK_OWNER_KEY = 'telegram.roxi_login.lock_owner';

    public const ACTIVE_CACHE_KEY = 'telegram.roxi_login.in_progress';

    private const FLOW_TTL_SECONDS = 900;

    public function __construct(private readonly RoxiLoginSessionClient $client) {}

    public function start(StartRoxiLoginData $data, Store $session): RoxiLoginStep
    {
        $this->assertConfigured();

        if ($session->has(self::SESSION_LOCK_OWNER_KEY)) {
            if ($this->ownsFlowLock((string) $session->get(self::SESSION_LOCK_OWNER_KEY))) {
                throw new RoxiLoginException('login_in_progress', 'Continue or cancel the Roxi sign-in already in progress.', 409, true);
            }

            $this->clearSession($session);
        }

        $owner = (string) Str::ulid()->toBase32();
        $lock = Cache::lock($this->workerLockName(), self::FLOW_TTL_SECONDS, $owner);

        if (! $lock->get()) {
            throw new RoxiLoginException('session_busy', 'The Roxi Telegram session is in use. Stop the worker and try again.', 409);
        }

        $session->put(self::SESSION_LOCK_OWNER_KEY, $owner);
        $session->put(self::SESSION_STEP_KEY, RoxiLoginStep::AwaitingPhone->value);
        Cache::put(self::ACTIVE_CACHE_KEY, $owner, self::FLOW_TTL_SECONDS);

        try {
            if ($this->client->isAuthorized()) {
                if ($this->matchesConfiguredUsername($this->client->username())) {
                    return $this->complete($session, $lock);
                }

                $this->client->logout();
            }

            $this->client->requestCode($data->phone);
            $session->put(self::SESSION_STEP_KEY, RoxiLoginStep::AwaitingCode->value);

            return RoxiLoginStep::AwaitingCode;
        } catch (Throwable $exception) {
            $this->handleFailure($exception, $session, $lock);
        }
    }

    public function completeCode(CompleteRoxiLoginCodeData $data, Store $session): RoxiLoginStep
    {
        $lock = $this->flowLock($session, RoxiLoginStep::AwaitingCode);

        try {
            if ($this->client->completeCode($data->code)) {
                $session->put(self::SESSION_STEP_KEY, RoxiLoginStep::AwaitingPassword->value);

                return RoxiLoginStep::AwaitingPassword;
            }

            return $this->complete($session, $lock);
        } catch (Throwable $exception) {
            $this->handleFailure($exception, $session, $lock);
        }
    }

    public function completePassword(CompleteRoxiLoginPasswordData $data, Store $session): RoxiLoginStep
    {
        $lock = $this->flowLock($session, RoxiLoginStep::AwaitingPassword);

        try {
            $this->client->completePassword($data->password);

            return $this->complete($session, $lock);
        } catch (Throwable $exception) {
            $this->handleFailure($exception, $session, $lock);
        }
    }

    public function cancel(Store $session): void
    {
        $owner = $session->get(self::SESSION_LOCK_OWNER_KEY);

        if (is_string($owner) && $this->ownsFlowLock($owner)) {
            $lock = Cache::lock($this->workerLockName(), self::FLOW_TTL_SECONDS, $owner);

            try {
                $this->client->logout();
            } finally {
                $this->releaseFlow($session, $lock, RoxiLoginStep::AwaitingPhone);
            }

            return;
        }

        $this->clearSession($session, RoxiLoginStep::AwaitingPhone);
    }

    public function step(Store $session): RoxiLoginStep
    {
        $owner = $session->get(self::SESSION_LOCK_OWNER_KEY);

        if (is_string($owner) && ! $this->ownsFlowLock($owner)) {
            $this->forgetActiveMarker($owner);
            $this->clearSession($session, RoxiLoginStep::AwaitingPhone);
        }

        $step = $session->get(self::SESSION_STEP_KEY, RoxiLoginStep::AwaitingPhone->value);

        return is_string($step) ? (RoxiLoginStep::tryFrom($step) ?? RoxiLoginStep::AwaitingPhone) : RoxiLoginStep::AwaitingPhone;
    }

    public function isInProgress(): bool
    {
        return Cache::has(self::ACTIVE_CACHE_KEY);
    }

    private function flowLock(Store $session, RoxiLoginStep $expectedStep): Lock
    {
        $owner = $session->get(self::SESSION_LOCK_OWNER_KEY);
        $step = $session->get(self::SESSION_STEP_KEY);

        if (! is_string($owner) || $step !== $expectedStep->value) {
            throw new RoxiLoginException('login_expired', 'This Roxi sign-in expired. Start again.', 409);
        }

        $lock = Cache::lock($this->workerLockName(), self::FLOW_TTL_SECONDS, $owner);

        if (! $lock->isOwnedBy($owner)) {
            $this->forgetActiveMarker($owner);
            $this->clearSession($session, RoxiLoginStep::AwaitingPhone);

            throw new RoxiLoginException('login_expired', 'This Roxi sign-in expired. Start again.', 409);
        }

        return $lock;
    }

    private function complete(Store $session, Lock $lock): RoxiLoginStep
    {
        $expected = ltrim(trim((string) config('telegram.roxi.username')), '@');
        $username = $this->client->username();

        if (! $this->matchesConfiguredUsername($username)) {
            $this->client->logout();

            throw new RoxiLoginException(
                'account_mismatch',
                'The signed-in Telegram account does not match @'.$expected.'.',
            );
        }

        return $this->releaseFlow($session, $lock, RoxiLoginStep::Connected);
    }

    private function releaseFlow(Store $session, Lock $lock, RoxiLoginStep $step): RoxiLoginStep
    {
        $owner = $session->get(self::SESSION_LOCK_OWNER_KEY);
        $lock->release();

        if (is_string($owner)) {
            $this->forgetActiveMarker($owner);
        }

        $session->forget(self::SESSION_LOCK_OWNER_KEY);
        $session->put(self::SESSION_STEP_KEY, $step->value);

        return $step;
    }

    private function handleFailure(Throwable $exception, Store $session, Lock $lock): never
    {
        if ($exception instanceof RoxiLoginException && $exception->keepAttempt) {
            throw $exception;
        }

        if (! ($exception instanceof RoxiLoginException && $exception->reason === 'account_mismatch')) {
            $this->client->logout();
        }

        $this->releaseFlow($session, $lock, RoxiLoginStep::AwaitingPhone);

        if ($exception instanceof RoxiLoginException) {
            throw $exception;
        }

        throw new RoxiLoginException('unavailable', 'Telegram sign-in failed. Start again.', 503);
    }

    private function assertConfigured(): void
    {
        if (! config('telegram.app_id') || ! config('telegram.api_hash')) {
            throw new RoxiLoginException('not_configured', 'TELEGRAM_APP_ID and TELEGRAM_API_HASH must be configured.', 503);
        }

        if (! config('telegram.roxi.username')) {
            throw new RoxiLoginException('not_configured', 'TELEGRAM_ROXI_USERNAME must be configured.', 503);
        }
    }

    private function matchesConfiguredUsername(?string $username): bool
    {
        $expected = mb_strtolower(ltrim(trim((string) config('telegram.roxi.username')), '@'));

        return is_string($username)
            && $expected !== ''
            && hash_equals($expected, mb_strtolower(ltrim($username, '@')));
    }

    private function ownsFlowLock(string $owner): bool
    {
        return Cache::lock($this->workerLockName(), self::FLOW_TTL_SECONDS, $owner)->isOwnedBy($owner);
    }

    private function workerLockName(): string
    {
        return (string) config('telegram.worker.lock', 'roxi-runtime');
    }

    private function forgetActiveMarker(string $owner): void
    {
        if (Cache::get(self::ACTIVE_CACHE_KEY) === $owner) {
            Cache::forget(self::ACTIVE_CACHE_KEY);
        }
    }

    private function clearSession(Store $session, RoxiLoginStep $step = RoxiLoginStep::AwaitingPhone): void
    {
        $session->forget(self::SESSION_LOCK_OWNER_KEY);
        $session->put(self::SESSION_STEP_KEY, $step->value);
    }
}
