<?php

namespace App\Providers;

use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Contracts\PersonalTelegramSessionClient;
use App\Domains\Telegram\Contracts\RoxiLoginSessionClient;
use App\Domains\Telegram\Contracts\TelegramDownloadStreamTransport;
use App\Domains\Telegram\Contracts\TelegramPeerClient;
use App\Domains\Telegram\Contracts\TelegramUploadStreamTransport;
use App\Domains\Telegram\Contracts\TelegramWorkerLauncher;
use App\Domains\Telegram\Runtime\ConsoleTelegramWorkerLauncher;
use App\Domains\Telegram\Runtime\MadelineProtoPeerClient;
use App\Domains\Telegram\Runtime\MadelineProtoPersonalTelegramSessionClient;
use App\Domains\Telegram\Runtime\MadelineProtoRoxiLoginSessionClient;
use App\Domains\Telegram\Runtime\OperationLease;
use App\Domains\Telegram\Runtime\PersonalTelegramRuntimeManager;
use App\Domains\Telegram\Runtime\RoxiRuntime;
use App\Domains\Telegram\Runtime\TelegramQueueConsumer;
use App\Domains\Telegram\Runtime\TelegramUploadStreamBridge;
use App\Domains\Telegram\Runtime\TelegramUploadStreamServer;
use App\Domains\Telegram\Services\AmpTelegramHttpHandler;
use App\Domains\Telegram\Services\WorkerTelegramDownloadStreamTransport;
use App\Domains\Telegram\Services\WorkerTelegramUploadStreamTransport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Process-local handle on the running Roxi MTProto client: the worker
        // event handler binds the live client, drivers and peer messaging
        // read it — must be the same instance for the whole process.
        $this->app->singleton(RoxiRuntime::class);
        $this->app->singleton(OperationLease::class);
        $this->app->singleton(AmpTelegramHttpHandler::class);
        $this->app->singleton(TelegramQueueConsumer::class);
        $this->app->singleton(PersonalTelegramSessionClient::class, MadelineProtoPersonalTelegramSessionClient::class);
        $this->app->singleton(RoxiLoginSessionClient::class, MadelineProtoRoxiLoginSessionClient::class);
        $this->app->singleton(PersonalTelegramRuntimeManager::class);
        $this->app->singleton(TelegramUploadStreamBridge::class);
        $this->app->singleton(TelegramUploadStreamServer::class);

        // Worker-side peer messaging (ADR-0001 invite provisioning).
        $this->app->singleton(TelegramPeerClient::class, MadelineProtoPeerClient::class);
        $this->app->bind(TelegramUploadStreamTransport::class, WorkerTelegramUploadStreamTransport::class);
        $this->app->bind(TelegramDownloadStreamTransport::class, WorkerTelegramDownloadStreamTransport::class);

        $this->app->bind(TelegramWorkerLauncher::class, ConsoleTelegramWorkerLauncher::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Super admin is the only role allowed to boot the Telegram runtime
        // (settings page worker start). Bootstrap the first super admin via
        // `php artisan user:make-super-admin`.
        Gate::define('start-telegram-worker', fn (User $user): bool => $user->isSuperAdmin());
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
