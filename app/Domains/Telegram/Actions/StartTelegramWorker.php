<?php

namespace App\Domains\Telegram\Actions;

use App\Domains\Telegram\Contracts\TelegramWorkerLauncher;
use App\Domains\Telegram\Enums\WorkerStartOutcome;
use App\Domains\Telegram\Models\TelegramWorkerStatus;

/**
 * Start the Telegram worker on behalf of a super admin (settings page).
 *
 * Idempotent: a worker with a fresh heartbeat is left alone. The freshly
 * spawned process reports readiness through its own heartbeat, which the
 * status endpoint surfaces.
 */
class StartTelegramWorker
{
    public function __construct(
        private readonly TelegramWorkerLauncher $launcher,
        private readonly AuthenticateRoxiTelegramAccount $roxiLogin,
    ) {}

    public function handle(): WorkerStartOutcome
    {
        if ($this->roxiLogin->isInProgress()) {
            return WorkerStartOutcome::LoginInProgress;
        }

        if (TelegramWorkerStatus::query()->find(1)?->isRunning()) {
            return WorkerStartOutcome::AlreadyRunning;
        }

        $this->launcher->launch();

        return WorkerStartOutcome::Started;
    }
}
