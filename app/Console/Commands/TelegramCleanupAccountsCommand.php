<?php

namespace App\Console\Commands;

use App\Domains\Files\Actions\CleanupUserStorage;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Runtime\TelegramErrors;
use Illuminate\Console\Command;
use Throwable;

class TelegramCleanupAccountsCommand extends Command
{
    protected $signature = 'telegram:cleanup-accounts {--retry-failed : Retry known failed remote deletions after resolving their cause}';

    protected $description = 'Continue durable account cleanup and report accounts that still retain remote content';

    public function handle(CleanupUserStorage $cleanup): int
    {
        User::query()->whereNotNull('deletion_requested_at')->eachById(function (User $user) use ($cleanup): void {
            try {
                $finished = $cleanup->handle($user, (bool) $this->option('retry-failed'));
                $this->line('Account '.$user->id.($finished ? ': cleanup completed.' : ': cleanup pending; inspect retained operations.'));
            } catch (Throwable $error) {
                $this->error('Account '.$user->id.': '.TelegramErrors::message($error->getMessage()));
            }
        });

        return self::SUCCESS;
    }
}
