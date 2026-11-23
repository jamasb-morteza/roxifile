<?php

namespace Tests\Feature\Telegram;

use App\Domains\Files\Models\FileOperation;
use App\Domains\Telegram\Jobs\TelegramOperationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OperationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_requeues_abandoned_work_but_preserves_live_owners_and_unknown_uploads(): void
    {
        $this->freezeTime();
        $pending = FileOperation::factory()->create(['status' => 'pending', 'updated_at' => now()->subMinutes(5)]);
        $unknown = FileOperation::factory()->create([
            'operation' => 'upload', 'status' => 'uploading', 'updated_at' => now()->subMinutes(5),
            'metadata' => ['send_started_at' => now()->subMinutes(5)->toIso8601String()],
        ]);
        $live = FileOperation::factory()->create(['status' => 'pending', 'updated_at' => now()->subMinutes(5)]);
        $live->forceFill(['lease_token' => 'live-owner', 'lease_expires_at' => now()->addMinutes(2)])->save();
        Queue::fake([TelegramOperationJob::class]);

        $this->artisan('telegram:recover-operations')->assertSuccessful();

        Queue::assertPushed(TelegramOperationJob::class, fn ($job): bool => $job->operationId === $pending->id);
        Queue::assertNotPushed(TelegramOperationJob::class, fn ($job): bool => in_array($job->operationId, [$live->id, $unknown->id], true));
        $this->assertSame('needs_review', $unknown->fresh()->status->value);
        $this->artisan('telegram:recover-operations')->assertSuccessful();
        Queue::assertPushed(TelegramOperationJob::class, 1);
    }
}
