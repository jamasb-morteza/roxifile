<?php

namespace App\Domains\Telegram\Contracts;

/**
 * Boots a detached Telegram worker process (telegram:worker) outside the
 * web request. Implementations must not block until the worker is up: the
 * runtime reports readiness via its telegram_worker_status heartbeat.
 */
interface TelegramWorkerLauncher
{
    public function launch(): void;
}
