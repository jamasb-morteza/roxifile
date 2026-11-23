<?php

namespace App\Console\Commands;

use App\Domains\Telegram\Actions\EnsureRoxiAccount;
use App\Domains\Telegram\Runtime\MadelineProtoFactory;
use App\Domains\Telegram\Runtime\TelegramErrors;
use danog\MadelineProto\API;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * One-time interactive login for the system Roxi Telegram account.
 *
 * Creates/updates the MadelineProto session (phone → code → 2FA if enabled)
 * persisted in the dedicated MadelineProto PostgreSQL database. The worker
 * later loads this session once and keeps it alive (spec §13).
 */
class TelegramRoxiLoginCommand extends Command
{
    protected $signature = 'telegram:roxi:login';

    protected $description = 'Interactively log in the Roxi system Telegram account (MTProto session bootstrap)';

    public function handle(MadelineProtoFactory $factory): int
    {
        if (! config('telegram.app_id') || ! config('telegram.api_hash')) {
            $this->error('TELEGRAM_APP_ID and TELEGRAM_API_HASH must be configured in your environment.');

            return self::FAILURE;
        }

        if (! config('telegram.roxi.username')) {
            $this->error('TELEGRAM_ROXI_USERNAME must be configured (the @username Roxi will use on Telegram).');

            return self::FAILURE;
        }

        app(EnsureRoxiAccount::class)();

        $session = $factory->roxiSessionName();
        File::ensureDirectoryExists(dirname($session), 0700);

        $this->info('Starting interactive MadelineProto login for session: '.$session);
        $this->line('Follow the prompts: phone number (international format), the login code Telegram sends you, and your 2FA password if enabled.');

        try {
            /** @var API $api */
            $api = $factory->make(config('telegram'), $session);
            $api->start();

            $self = $api->getSelf();
            $this->info('Logged in as: '.($self['username'] ?? $self['first_name'] ?? 'unknown').'.');
            $this->line('You can now start the worker: php artisan telegram:worker');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Login failed: '.TelegramErrors::message($e->getMessage()));

            return self::FAILURE;
        }
    }
}
