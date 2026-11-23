<?php

namespace Tests\Feature\Telegram;

use App\Domains\Telegram\Jobs\DeliverRoxiConfirmationCode;
use App\Domains\Telegram\Jobs\ProcessPersonalTelegramLoginJob;
use App\Domains\Telegram\Jobs\ProvisionInviteConnection;
use App\Domains\Telegram\Jobs\ReconcileTelegramStorage;
use App\Domains\Telegram\Jobs\RetractInviteCode;
use App\Domains\Telegram\Jobs\RevokePersonalTelegramConnectionJob;
use App\Domains\Telegram\Jobs\RunRoxiStorageChatSetup;
use App\Domains\Telegram\Jobs\TelegramOperationJob;
use App\Domains\Telegram\Jobs\VerifyTelegramConnectionJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Telegram jobs must land on the redis/telegram queue: only telegram:worker
 * consumes it, and only that process holds the MadelineProto runtime. A job
 * routed anywhere else is never delivered (RoxiRuntime is process-bound).
 */
class TelegramJobRoutingTest extends TestCase
{
    public function test_telegram_jobs_route_to_the_worker_queue(): void
    {
        Queue::fake();

        DeliverRoxiConfirmationCode::dispatch(9, 'attempt-id');
        ProvisionInviteConnection::dispatch(1);
        ProcessPersonalTelegramLoginJob::dispatch(2);
        RevokePersonalTelegramConnectionJob::dispatch(3, 4);
        RetractInviteCode::dispatch(1);
        TelegramOperationJob::dispatch(1);
        ReconcileTelegramStorage::dispatch();
        VerifyTelegramConnectionJob::dispatch(1);
        RunRoxiStorageChatSetup::dispatch(1, 'request-id');

        Queue::assertPushedOn('telegram', DeliverRoxiConfirmationCode::class);
        Queue::assertPushedOn('telegram', ProvisionInviteConnection::class);
        Queue::assertPushedOn('telegram', ProcessPersonalTelegramLoginJob::class);
        Queue::assertPushedOn('telegram', RevokePersonalTelegramConnectionJob::class);
        Queue::assertPushedOn('telegram', RetractInviteCode::class);
        Queue::assertPushedOn('telegram', TelegramOperationJob::class);
        Queue::assertPushedOn('telegram', ReconcileTelegramStorage::class);
        Queue::assertPushedOn('telegram', VerifyTelegramConnectionJob::class);
        Queue::assertPushedOn('telegram', RunRoxiStorageChatSetup::class);
    }
}
