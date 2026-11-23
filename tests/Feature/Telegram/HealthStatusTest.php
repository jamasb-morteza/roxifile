<?php

namespace Tests\Feature\Telegram;

use App\Domains\Files\Models\File;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Models\TelegramWorkerStatus;
use App\Domains\Telegram\Runtime\WorkerHealth;
use App\Domains\Telegram\Runtime\WorkerLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_heartbeat_creates_readable_worker_health(): void
    {
        $user = User::factory()->create();

        app(WorkerHealth::class)->heartbeat(roxiConnected: true, sessionLoaded: true);

        $this->actingAs($user)->getJson('/telegram/worker-status')
            ->assertOk()
            ->assertJsonPath('running', true)
            ->assertJsonPath('session_loaded', true);
    }

    public function test_runtime_lock_refuses_another_owner_and_detects_expiry(): void
    {
        $this->freezeTime();
        $first = new WorkerLock('lifecycle-test', 5);
        $second = new WorkerLock('lifecycle-test', 5);

        $this->assertTrue($first->acquire());
        $this->assertFalse($second->acquire());
        $this->travel(6)->seconds();
        $this->assertTrue($second->acquire());
        $this->assertFalse($first->refresh());
        $first->release();
        $this->assertTrue($second->refresh());
        $second->release();
    }

    public function test_guests_cannot_read_worker_status(): void
    {
        $this->getJson('/telegram/worker-status')->assertUnauthorized();
    }

    public function test_fresh_heartbeat_reports_a_running_worker(): void
    {
        $user = User::factory()->create();

        (new TelegramWorkerStatus)->forceFill([
            'id' => 1,
            'pid' => 4242,
            'hostname' => 'testing',
            'started_at' => now()->subHour(),
            'last_heartbeat_at' => now()->subSeconds(10),
            'last_activity_at' => now()->subSeconds(5),
            'session_loaded' => true,
            'roxi_connected' => true,
            'reconnect_count' => 3,
        ])->save();

        $response = $this->actingAs($user)->getJson('/telegram/worker-status');

        $response->assertOk();
        $response->assertJsonPath('running', true);
        $response->assertJsonPath('roxi_connected', true);
        $response->assertJsonPath('session_loaded', true);
        $response->assertJsonPath('reconnect_count', 3);
        $response->assertJsonPath('flagged_files', 0);
    }

    public function test_stale_heartbeat_reports_a_stopped_worker(): void
    {
        $user = User::factory()->create();

        (new TelegramWorkerStatus)->forceFill([
            'id' => 1,
            'pid' => 4242,
            'hostname' => 'testing',
            'started_at' => now()->subHours(2),
            'last_heartbeat_at' => now()->subSeconds(120),
        ])->save();

        $response = $this->actingAs($user)->getJson('/telegram/worker-status');

        $response->assertOk();
        $response->assertJsonPath('running', false);
        $response->assertJsonPath('roxi_connected', false);
        $this->assertNull($response->json('last_error'));
    }

    public function test_missing_status_row_reports_a_stopped_worker(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/telegram/worker-status');

        $response->assertOk();
        $response->assertJsonPath('running', false);
        $response->assertJsonPath('flagged_files', 0);
    }

    public function test_reconciliation_flag_count_is_surfaced(): void
    {
        $user = User::factory()->create();

        (new TelegramWorkerStatus)->forceFill([
            'id' => 1,
            'hostname' => 'testing',
            'started_at' => now()->subHour(),
            'last_heartbeat_at' => now()->subSeconds(10),
        ])->save();

        File::factory()->uploaded()->create([
            'user_id' => $user->id,
            'metadata' => ['reconciliation' => ['flagged_at' => now()->toIso8601String(), 'kind' => 'message_missing']],
        ]);
        File::factory()->uploaded()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson('/telegram/worker-status');

        $response->assertOk();
        $response->assertJsonPath('flagged_files', 1);
    }

    public function test_a_stale_worker_cannot_mark_the_replacement_process_stopped(): void
    {
        $health = app(WorkerHealth::class);
        $health->heartbeat(true, true);
        TelegramWorkerStatus::query()->whereKey(1)->update(['pid' => getmypid() + 1]);

        $health->markStopped();

        $this->assertTrue(TelegramWorkerStatus::query()->findOrFail(1)->isRunning());
    }
}
