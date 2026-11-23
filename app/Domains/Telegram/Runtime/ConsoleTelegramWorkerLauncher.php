<?php

namespace App\Domains\Telegram\Runtime;

use App\Domains\Telegram\Contracts\TelegramWorkerLauncher;
use Symfony\Component\Process\Process;

/**
 * Spawns `php artisan telegram:worker` as a detached background process.
 *
 * The subshell `( cmd & )` re-parents the worker outside the web request so
 * it survives the response; output is appended to a dedicated log file.
 * The runtime lock in TelegramWorkerCommand keeps concurrent spawns single.
 */
class ConsoleTelegramWorkerLauncher implements TelegramWorkerLauncher
{
    public function launch(): void
    {
        $artisan = escapeshellarg((string) config('telegram.worker.php_binary', 'php'))
            .' '.escapeshellarg(base_path('artisan'));
        $logFile = escapeshellarg(storage_path('logs/telegram-worker.log'));

        $process = Process::fromShellCommandline(
            "({$artisan} telegram:worker >> {$logFile} 2>&1 &)",
            base_path(),
        );

        $process->run();
    }
}
