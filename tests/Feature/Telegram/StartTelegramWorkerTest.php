<?php

namespace Tests\Feature\Telegram;

use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Contracts\TelegramWorkerLauncher;
use App\Domains\Telegram\Models\TelegramWorkerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StartTelegramWorkerTest extends TestCase
{
    use RefreshDatabase;

    public int $launches = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->launches = 0;

        $this->app->instance(TelegramWorkerLauncher::class, new class($this) implements TelegramWorkerLauncher
        {
            public function __construct(private readonly StartTelegramWorkerTest $test) {}

            public function launch(): void
            {
                $this->test->launches++;
            }
        });
    }

    public function test_super_admin_can_start_the_worker(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($admin)->postJson('/telegram/worker/start');

        $response->assertOk();
        $this->assertSame('started', $response->json('outcome'));
        $this->assertSame(1, $this->launches);
    }

    public function test_regular_user_is_forbidden(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/telegram/worker/start');

        $response->assertForbidden();
        $this->assertSame(0, $this->launches);
    }

    public function test_start_is_skipped_while_the_heartbeat_is_fresh(): void
    {
        TelegramWorkerStatus::query()->forceCreate([
            'id' => 1,
            'pid' => 1234,
            'hostname' => 'test',
            'last_heartbeat_at' => now(),
        ]);

        $admin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($admin)->postJson('/telegram/worker/start');

        $response->assertOk();
        $this->assertSame('already_running', $response->json('outcome'));
        $this->assertSame(0, $this->launches);
    }

    public function test_stale_heartbeat_allows_a_new_start(): void
    {
        TelegramWorkerStatus::query()->forceCreate([
            'id' => 1,
            'pid' => 1234,
            'hostname' => 'test',
            'last_heartbeat_at' => now()->subMinutes(2),
        ]);

        $admin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($admin)->postJson('/telegram/worker/start');

        $response->assertOk();
        $this->assertSame('started', $response->json('outcome'));
        $this->assertSame(1, $this->launches);
    }

    public function test_worker_start_is_refused_while_the_roxi_login_flow_uses_its_session(): void
    {
        Cache::put('telegram.roxi_login.in_progress', 'owner', 900);
        $admin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($admin)->postJson('/telegram/worker/start');

        $response->assertConflict()->assertJsonPath('outcome', 'login_in_progress');
        $this->assertSame(0, $this->launches);
    }
}
