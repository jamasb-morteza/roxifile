<?php

namespace App\Console\Commands;

use App\Domains\Telegram\Actions\PollBotUpdates;
use App\Domains\Telegram\Actions\ReceiveTelegramImport;
use App\Domains\Telegram\Runtime\MadelineProtoFactory;
use App\Domains\Telegram\Runtime\OperationLease;
use App\Domains\Telegram\Runtime\RoxiEventHandler;
use App\Domains\Telegram\Runtime\RoxiRuntime;
use App\Domains\Telegram\Runtime\TelegramErrors;
use App\Domains\Telegram\Runtime\TelegramQueueConsumer;
use App\Domains\Telegram\Runtime\TelegramUploadStreamServer;
use App\Domains\Telegram\Runtime\WorkerHealth;
use App\Domains\Telegram\Runtime\WorkerHeartbeat;
use App\Domains\Telegram\Runtime\WorkerLock;
use danog\MadelineProto\API;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Revolt\EventLoop;
use Throwable;

/**
 * Long-running Telegram runtime (spec §10-§12).
 *
 * Boots MadelineProto ONCE for the Roxi system account and keeps it alive,
 * consuming the `telegram` queue and Telegram updates inside the AMPHP loop.
 * A second concurrent instance refuses to start (Redis runtime lock).
 *
 * When MadelineProto is not configured (no credentials), the command still
 * runs a plain pop-loop so Bot-driver operations keep working.
 */
class TelegramWorkerCommand extends Command
{
    protected $signature = 'telegram:worker';

    protected $description = 'Run the long-running Telegram worker (MadelineProto runtime + telegram queue consumer)';

    public function handle(
        MadelineProtoFactory $factory,
        WorkerHealth $health,
        TelegramUploadStreamServer $uploadStreamServer,
    ): int {
        if (! config('telegram.app_id') || ! config('telegram.api_hash')) {
            $this->warn('TELEGRAM_APP_ID / TELEGRAM_API_HASH are not configured; running in Bot-API-only mode.');
        }

        $ttl = (int) config('telegram.worker.lock_ttl_seconds', 90);
        if ($ttl < 2) {
            $this->error('The runtime lock TTL must be at least two seconds.');

            return self::FAILURE;
        }
        $lock = new WorkerLock(
            (string) config('telegram.worker.lock', 'roxi-runtime'),
            $ttl,
        );

        if (! $lock->acquire()) {
            $this->error('Another Telegram worker is already running (lock "'.config('telegram.worker.lock').'"). Exiting.');

            return self::FAILURE;
        }

        pcntl_async_signals(true);
        $consumer = app(TelegramQueueConsumer::class);
        RoxiEventHandler::$stopRequested = false;
        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function () use ($consumer): void {
                $this->info('Shutdown signal received; finishing in-flight work...');
                $consumer->stop();
                RoxiEventHandler::$stopRequested = true;
            });
        }

        $heartbeat = new WorkerHeartbeat($lock, $health, app(OperationLease::class), app(RoxiRuntime::class));
        $previousErrorHandler = EventLoop::getErrorHandler();
        try {
            $health->register(sessionLoaded: false);
            $uploadStreamServer->start();
            $heartbeat->start(max(1, min(15, (int) config('telegram.worker.heartbeat_interval_seconds', 15), intdiv($ttl, 3))));
            if (config('telegram.app_id') && config('telegram.api_hash')) {
                return $this->runMadelineRuntime($factory, $health, $lock);
            }

            return $this->runPlainLoop($health, $lock, $consumer);
        } finally {
            $heartbeat->stop();
            $uploadStreamServer->stop();
            EventLoop::setErrorHandler($previousErrorHandler);
            app(RoxiRuntime::class)->unbind();
            try {
                $health->markStopped();
            } finally {
                $lock->release();
            }
        }
    }

    /**
     * Full runtime: MadelineProto event handler owns the loop; the handler's
     * cron methods pop the queue and heartbeat health.
     */
    private function runMadelineRuntime(
        MadelineProtoFactory $factory,
        WorkerHealth $health,
        WorkerLock $lock,
    ): int {
        RoxiEventHandler::$health = $health;
        RoxiEventHandler::$lock = $lock;

        $session = $factory->roxiSessionName();

        // MadelineProto's mkdir is non-recursive; nothing else creates the
        // parent, and a missing dir would kill the runtime at boot.
        File::ensureDirectoryExists(dirname($session), 0700);

        $this->info('Telegram worker starting (MadelineProto runtime, session: '.$session.').');

        try {
            $api = $factory->make(config('telegram'), $session);
            if ($api->getAuthorization() !== API::LOGGED_IN) {
                $this->error('Roxi session is not authorized. Run php artisan telegram:roxi:login interactively first.');

                return self::FAILURE;
            }
            API::startAndLoopMulti([$api], RoxiEventHandler::class);
        } catch (Throwable $e) {
            $health->recordError($e);
            $this->error('Telegram worker exited: '.TelegramErrors::message($e->getMessage()));

            return self::FAILURE;
        }

        if (! app(RoxiRuntime::class)->isRunning()) {
            $this->error('Roxi session is not authorized.');
            $this->line('Run: php artisan telegram:roxi:login');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Bot-API-only mode: plain blocking pop loop (no MadelineProto).
     */
    private function runPlainLoop(
        WorkerHealth $health,
        WorkerLock $lock,
        TelegramQueueConsumer $consumer,
    ): int {
        $queue = (string) config('telegram.worker.queue', 'telegram');
        $intervalMs = (int) config('telegram.worker.pop_interval_ms', 500);
        $heartbeatEvery = max(1, (int) config('telegram.worker.heartbeat_interval_seconds', 15));
        $lastHeartbeat = 0;
        $lastBotPoll = 0;
        $lastImportRecovery = 0;

        $this->info('Telegram worker starting (Bot-API-only mode).');

        while (! $consumer->shouldExit()) {
            $job = Queue::connection('redis')->pop($queue);

            if ($job !== null) {
                try {
                    $job->fire();
                } catch (Throwable $e) {
                    $health->recordError($e);
                    $this->error('Job failed: '.TelegramErrors::message($e->getMessage()));
                }
            }

            if (RoxiEventHandler::$stopRequested) {
                return $consumer->isStopping() ? self::SUCCESS : self::FAILURE;
            }
            if (time() - $lastHeartbeat >= $heartbeatEvery) {
                if (! $lock->refresh()) {
                    $this->error('Runtime lock lost; stopping the worker.');

                    return self::FAILURE;
                }
                $health->heartbeat(roxiConnected: false, sessionLoaded: false);
                $lastHeartbeat = time();
            }

            if (time() - $lastBotPoll >= 3) {
                try {
                    app(PollBotUpdates::class)->handle();
                } catch (Throwable $e) {
                    $health->recordError($e);
                }
                $lastBotPoll = time();
            }

            if (time() - $lastImportRecovery >= 60) {
                app(ReceiveTelegramImport::class)->recover();
                $lastImportRecovery = time();
            }

            \Amp\delay(max(1, $intervalMs) / 1000);
            pcntl_signal_dispatch();
        }

        $this->info('Worker stopped gracefully.');

        return self::SUCCESS;
    }
}
