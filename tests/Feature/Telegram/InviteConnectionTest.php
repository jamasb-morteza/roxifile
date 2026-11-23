<?php

namespace Tests\Feature\Telegram;

use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Enums\TelegramAccountStatus;
use App\Domains\Telegram\Jobs\ProvisionInviteConnection;
use App\Domains\Telegram\Jobs\RetractInviteCode;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Services\RoxiProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class InviteConnectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_invite_start_creates_pending_connection_with_hashed_code_and_dispatches_provisioning(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user)->postJson('/telegram/connections/invite', [
            'name' => 'Alice storage',
            'target' => '@Target_User',
        ]);

        $response->assertStatus(201);
        $this->assertNotEmpty($response->json('code'));
        $this->assertStringStartsWith('ROXI-', (string) $response->json('code'));
        $this->assertSame('invite', $response->json('connection.method'));

        $connection = TelegramConnection::query()->where('method', 'invite')->firstOrFail();
        $this->assertSame($this->user->id, $connection->user_id);
        $this->assertSame('pending', $connection->status->value);
        $this->assertSame('target_user', $connection->target_identifier);
        $this->assertSame('Alice storage', $connection->name);
        $this->assertNotNull($connection->provision_code_hash);
        $this->assertNotSame($response->json('code'), $connection->provision_code_hash);
        $this->assertTrue($connection->provision_code_expires_at->isFuture());
        // Invite codes use the 5-minute invite TTL, not the 15-minute default.
        $this->assertSame(
            5.0,
            $connection->created_at->diffInMinutes($connection->provision_code_expires_at),
        );

        $roxi = TelegramAccount::query()->where('is_system', true)->where('method', 'roxi')->firstOrFail();
        $this->assertSame($roxi->id, $connection->telegram_account_id);

        Queue::assertPushed(ProvisionInviteConnection::class);
    }

    public function test_invite_start_rejects_invalid_target(): void
    {
        $response = $this->actingAs($this->user)->postJson('/telegram/connections/invite', [
            'name' => 'Broken',
            'target' => 'not a target!',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, TelegramConnection::query()->where('method', 'invite')->count());
    }

    public function test_invite_start_allows_another_connection_for_an_already_connected_target(): void
    {
        Queue::fake([ProvisionInviteConnection::class]);
        $roxi = TelegramAccount::factory()->create([
            'method' => 'roxi',
            'is_system' => true,
        ]);
        TelegramConnection::factory()->connected()->create([
            'user_id' => $this->user->id,
            'telegram_account_id' => $roxi->id,
            'method' => 'invite',
            'chat_username' => 'TakenUser',
        ]);

        $response = $this->actingAs($this->user)->postJson('/telegram/connections/invite', [
            'name' => 'Dup',
            'target' => '@takenuser',
        ]);

        $response->assertCreated();
        $this->assertSame('pending', $response->json('connection.status'));
        $this->assertSame(2, TelegramConnection::query()->count());
        Queue::assertPushed(ProvisionInviteConnection::class);
    }

    public function test_invite_start_creates_independent_pending_connections_for_the_same_target(): void
    {
        Queue::fake();

        $existing = TelegramConnection::factory()->create([
            'user_id' => $this->user->id,
            'method' => 'invite',
            'status' => 'pending',
            'target_identifier' => 'target_user',
            'metadata' => ['provisioning' => 'invite'],
        ]);
        $originalCode = app(RoxiProvisioningService::class)->issueCode($existing);
        $originalHash = $existing->provision_code_hash;

        $response = $this->actingAs($this->user)->postJson('/telegram/connections/invite', [
            'name' => 'Alice storage',
            'target' => '@Target_User',
        ]);

        $response->assertStatus(201);
        $this->assertNotSame($existing->id, $response->json('connection.id'));
        $this->assertSame(2, TelegramConnection::query()->where('method', 'invite')->count());
        $this->assertSame($originalHash, $existing->refresh()->provision_code_hash);
        $this->assertNotSame($originalCode, $response->json('code'));
        $created = TelegramConnection::findOrFail($response->json('connection.id'));
        $this->assertNotNull($created->provision_code_hash);
        $this->assertTrue($created->provision_code_expires_at->isFuture());

        Queue::assertPushed(ProvisionInviteConnection::class);
    }

    public function test_regenerate_rotates_invite_code_and_redispatches_provisioning(): void
    {
        Queue::fake();

        $connection = TelegramConnection::factory()->invite(555_000, 'target_user')->create([
            'user_id' => $this->user->id,
            'status' => 'pending',
            'provision_code_hash' => 'stale-hash',
            'provision_code_expires_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/telegram/connections/{$connection->id}/provisioning-code",
        );

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('code'));
        $this->assertNotSame('stale-hash', $connection->refresh()->provision_code_hash);
        $this->assertTrue($connection->provision_code_expires_at->isFuture());

        Queue::assertPushed(
            ProvisionInviteConnection::class,
            fn (ProvisionInviteConnection $job): bool => $job->connectionId === $connection->id,
        );
    }

    public function test_regenerate_denied_for_another_users_invite_connection(): void
    {
        $connection = TelegramConnection::factory()->invite(555_000, 'target_user')->create([
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/telegram/connections/{$connection->id}/provisioning-code",
        );

        $response->assertStatus(403);
    }

    public function test_sweep_deletes_expired_pending_invites_and_queues_retraction(): void
    {
        Queue::fake();

        $expiredWithDm = $this->makeInvite(
            status: 'pending',
            metadata: ['provisioning' => 'invite', 'dm_chat_id' => 555_000, 'dm_message_id' => 321],
        );
        $expiredWithDm->forceFill(['provision_code_expires_at' => now()->subMinutes(10)])->save();

        $expiredWithoutDm = $this->makeInvite(status: 'pending');
        $expiredWithoutDm->forceFill(['provision_code_expires_at' => now()->subMinutes(10)])->save();

        $fresh = $this->makeInvite(status: 'pending');
        $fresh->forceFill(['provision_code_expires_at' => now()->addMinutes(4)])->save();

        $connected = $this->makeInvite(status: 'connected');
        $connected->forceFill(['provision_code_expires_at' => now()->subMinutes(10)])->save();

        $this->artisan('telegram:prune-pending-invites')->assertSuccessful();

        // The DM'd row survives until the worker retracts the message; the
        // DM-less one is hard-deleted immediately.
        $this->assertDatabaseHas('telegram_connections', ['id' => $expiredWithDm->id]);
        Queue::assertPushed(
            RetractInviteCode::class,
            fn (RetractInviteCode $job): bool => $job->connectionId === $expiredWithDm->id,
        );

        $this->assertDatabaseMissing('telegram_connections', ['id' => $expiredWithoutDm->id]);
        $this->assertDatabaseHas('telegram_connections', ['id' => $fresh->id]);
        $this->assertDatabaseHas('telegram_connections', ['id' => $connected->id]);
    }

    private function makeInvite(string $status, array $metadata = ['provisioning' => 'invite']): TelegramConnection
    {
        $connection = TelegramConnection::factory()->invite(555_000, 'target_user')->create([
            'user_id' => $this->user->id,
            'status' => $status,
            'target_user_id' => $status === 'pending' ? null : 555_000,
            'metadata' => $metadata,
        ]);

        $connection->forceFill([
            'provision_code_hash' => hash('sha256', 'ROXI-AAAA-BBBB'),
            'provision_code_expires_at' => now()->addMinutes(5),
        ])->save();

        return $connection;
    }

    public function test_accounts_page_exposes_only_masked_code_for_verified_invites(): void
    {
        $connection = $this->makeInvite(status: 'connected');
        $connection->forceFill(['provision_code_encrypted' => 'ROXI-AAAA-B3B4'])->save();

        $response = $this->actingAs($this->user)->get('/telegram/connections');

        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('telegram/Connections')
                ->where('connections.0.provision_code_masked', 'B3B4'),
        );
        // The full code never reaches the browser.
        $response->assertDontSee('ROXI-AAAA-B3B4');
    }

    public function test_invite_start_returns_503_when_roxi_account_is_errored(): void
    {
        TelegramAccount::factory()->create([
            'method' => 'roxi',
            'is_system' => true,
            'status' => TelegramAccountStatus::Error,
        ]);

        $response = $this->actingAs($this->user)->postJson('/telegram/connections/invite', [
            'name' => 'Alice storage',
            'target' => '@targetuser',
        ]);

        $response->assertStatus(503);
        $this->assertSame(0, TelegramConnection::query()->where('method', 'invite')->count());
    }
}
