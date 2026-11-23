<?php

namespace Tests\Feature\Telegram;

use App\Domains\Telegram\Contracts\PeerResolutionFailed;
use App\Domains\Telegram\Contracts\TelegramPeerClient;
use App\Domains\Telegram\Drivers\FakeTelegramPeerClient;
use App\Domains\Telegram\Jobs\DeliverRoxiConfirmationCode;
use App\Domains\Telegram\Jobs\ProvisionInviteConnection;
use App\Domains\Telegram\Jobs\RetractInviteCode;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Models\TelegramConnectionAttempt;
use App\Domains\Telegram\Runtime\MadelineProtoPeerClient;
use App\Domains\Telegram\Runtime\RoxiRuntime;
use App\Domains\Telegram\Services\RoxiProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InviteProvisioningWorkerTest extends TestCase
{
    use RefreshDatabase;

    private FakeTelegramPeerClient $peer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->peer = new FakeTelegramPeerClient;
        $this->app->instance(TelegramPeerClient::class, $this->peer);
    }

    private function pendingInvite(array $attributes = []): TelegramConnection
    {
        $connection = TelegramConnection::factory()->invite(555_000, 'target_user')->create([
            'status' => 'pending',
            'target_user_id' => null,
            ...$attributes,
        ]);

        $connection->forceFill([
            'provision_code_hash' => hash('sha256', 'ROXI-AAAA-BBBB'),
            'provision_code_encrypted' => 'ROXI-AAAA-BBBB',
            'provision_code_expires_at' => now()->addMinutes(5),
        ])->save();

        return $connection;
    }

    public function test_phone_resolution_uses_the_imported_user_id_and_corresponding_user(): void
    {
        $runtime = $this->mock(RoxiRuntime::class);
        $runtime->shouldReceive('methodCallAsyncRead')->with('contacts.importContacts', \Mockery::type('array'))
            ->once()->andReturn([
                'imported' => [['_' => 'importedContact', 'user_id' => 12345, 'client_id' => 7]],
                'users' => [['_' => 'user', 'id' => 12345, 'first_name' => 'Target', 'username' => 'target_user']],
            ]);

        $peer = app(MadelineProtoPeerClient::class)->resolveTarget('+15551234567');

        $this->assertSame(12345, $peer->userId);
        $this->assertSame('target_user', $peer->username);
    }

    public function test_provisioning_op_resolves_target_and_delivers_code_as_dm(): void
    {
        $connection = $this->pendingInvite();

        (new ProvisionInviteConnection($connection->id))->handle(
            $this->peer,
            app(RoxiProvisioningService::class),
        );

        $connection->refresh();
        $this->assertSame(555_000, $connection->target_user_id);
        $this->assertSame(555_000, $connection->metadata['dm_chat_id'] ?? null);
        $this->assertSame(401, $connection->metadata['dm_message_id'] ?? null);
        $this->assertSame('pending', $connection->status->value);

        $resolve = $this->peer->calls[0] ?? [];
        $this->assertSame('resolveTarget', $resolve['method'] ?? null);
        $this->assertSame('target_user', $resolve['identifier'] ?? null);

        $send = $this->peer->calls[1] ?? [];
        $this->assertSame('sendMessage', $send['method'] ?? null);
        $this->assertSame(555_000, $send['chat_id'] ?? null);
        $this->assertStringContainsString('ROXI-AAAA-BBBB', (string) ($send['text'] ?? ''));
    }

    public function test_provisioning_op_skips_resolution_when_target_already_resolved(): void
    {
        $connection = $this->pendingInvite(['target_user_id' => 555_000]);

        (new ProvisionInviteConnection($connection->id))->handle(
            $this->peer,
            app(RoxiProvisioningService::class),
        );

        $methods = array_column($this->peer->calls, 'method');
        $this->assertNotContains('resolveTarget', $methods);
        $this->assertContains('sendMessage', $methods);
    }

    public function test_provisioning_op_marks_error_when_target_unresolvable(): void
    {
        $this->peer->throwOnResolve = new PeerResolutionFailed('unreachable');

        $connection = $this->pendingInvite();

        (new ProvisionInviteConnection($connection->id))->handle(
            $this->peer,
            app(RoxiProvisioningService::class),
        );

        $connection->refresh();
        $this->assertSame('error', $connection->status->value);
        $this->assertNotNull($connection->last_error);
        $this->assertNull($connection->metadata['dm_message_id'] ?? null);
        $this->assertTrue(TelegramConnectionAttempt::query()
            ->where('telegram_connection_id', $connection->id)
            ->where('result', 'failure')
            ->exists());
    }

    public function test_permanent_delivery_failure_marks_pending_invite_as_failed(): void
    {
        $connection = $this->pendingInvite();

        (new ProvisionInviteConnection($connection->id))->failed(
            new MaxAttemptsExceededException('ProvisionInviteConnection has been attempted too many times.'),
        );

        $connection->refresh();
        $this->assertSame('error', $connection->status->value);
        $this->assertSame('The invite could not be delivered. Please try again.', $connection->last_error);
        $this->assertTrue(TelegramConnectionAttempt::query()
            ->where('telegram_connection_id', $connection->id)
            ->where('result', 'failure')
            ->where('metadata->event', 'invite_failed')
            ->exists());
    }

    public function test_failure_hook_leaves_connected_connection_untouched(): void
    {
        $connection = $this->pendingInvite(['status' => 'connected']);

        (new ProvisionInviteConnection($connection->id))->failed(
            new MaxAttemptsExceededException('ProvisionInviteConnection has been attempted too many times.'),
        );

        $connection->refresh();
        $this->assertSame('connected', $connection->status->value);
        $this->assertNull($connection->last_error);
    }

    public function test_provisioning_op_ignores_non_pending_connections(): void
    {
        $connection = $this->pendingInvite(['status' => 'connected']);

        (new ProvisionInviteConnection($connection->id))->handle(
            $this->peer,
            app(RoxiProvisioningService::class),
        );

        $this->assertSame([], $this->peer->calls);
    }

    public function test_invite_code_from_non_target_sender_is_ignored(): void
    {
        $service = app(RoxiProvisioningService::class);
        $connection = $this->pendingInvite(['target_user_id' => 555_000]);
        $code = $service->issueCode($connection);

        $matched = $service->handleIncomingCode($code, 999_999, [
            'chat_id' => 999_999,
            'chat_type' => 'user',
            'chat_title' => 'Stranger',
            'chat_username' => 'stranger',
        ]);

        $this->assertNull($matched);
        $this->assertSame('pending', $connection->refresh()->status->value);
    }

    public function test_invite_code_from_target_connects_and_retains_encrypted_code(): void
    {
        $service = app(RoxiProvisioningService::class);
        $connection = $this->pendingInvite(['target_user_id' => 555_000]);
        $code = $service->issueCode($connection);

        $matched = $service->handleIncomingCode($code, 555_000, [
            'chat_id' => 555_000,
            'chat_type' => 'user',
            'chat_title' => 'Target User',
            'chat_username' => 'target_user',
        ]);

        $this->assertNotNull($matched);
        $connection->refresh();
        $this->assertSame('connected', $connection->status->value);
        $this->assertSame(555_000, $connection->chat_id);
        $this->assertNull($connection->provision_code_hash);

        // The verifying code is retained encrypted: ciphertext at rest,
        // plaintext only through the encrypted cast.
        $this->assertNotSame($code, $connection->getRawOriginal('provision_code_encrypted'));
        $this->assertSame($code, $connection->provision_code_encrypted);

        // Single-use: the cleared hash can never match again.
        $this->assertNull($service->handleIncomingCode($code, 555_000, [
            'chat_id' => 555_000,
            'chat_type' => 'user',
            'chat_title' => 'Target User',
            'chat_username' => 'target_user',
        ]));
    }

    public function test_already_connected_target_cannot_connect_twice(): void
    {
        $roxi = TelegramAccount::factory()->create(['method' => 'roxi', 'is_system' => true]);

        TelegramConnection::factory()->invite(555_000, 'target_user')->create([
            'telegram_account_id' => $roxi->id,
            'status' => 'connected',
            'chat_id' => 555_000,
        ]);

        $service = app(RoxiProvisioningService::class);
        $connection = $this->pendingInvite([
            'telegram_account_id' => $roxi->id,
            'target_user_id' => 555_000,
        ]);
        $code = $service->issueCode($connection);

        $matched = $service->handleIncomingCode($code, 555_000, [
            'chat_id' => 555_000,
            'chat_type' => 'user',
            'chat_title' => 'Target User',
            'chat_username' => 'target_user',
        ]);

        $this->assertNotNull($matched);
        $this->assertSame('error', $matched->status->value);
        $this->assertStringContainsStringIgnoringCase('already connected', (string) $matched->last_error);
        $this->assertSame('connected', TelegramConnection::query()
            ->where('status', 'connected')
            ->whereKeyNot($connection->id)
            ->firstOrFail()
            ->status->value);
    }

    public function test_expired_invite_code_never_matches(): void
    {
        $service = app(RoxiProvisioningService::class);
        $connection = $this->pendingInvite(['target_user_id' => 555_000]);
        $code = $service->issueCode($connection);

        $connection->forceFill(['provision_code_expires_at' => now()->subMinute()])->save();

        $this->assertNull($service->handleIncomingCode($code, 555_000, [
            'chat_id' => 555_000,
            'chat_type' => 'user',
            'chat_title' => 'Target User',
            'chat_username' => 'target_user',
        ]));
    }

    public function test_retract_job_deletes_dm_and_force_deletes_expired_invite(): void
    {
        $connection = $this->pendingInvite([
            'target_user_id' => 555_000,
            'metadata' => ['provisioning' => 'invite', 'dm_chat_id' => 555_000, 'dm_message_id' => 321],
        ]);
        $connection->forceFill(['provision_code_expires_at' => now()->subMinutes(10)])->save();
        $id = $connection->id;

        (new RetractInviteCode($id))->handle($this->peer);

        $retract = collect($this->peer->calls)->firstWhere('method', 'deleteSentMessage');
        $this->assertNotNull($retract);
        $this->assertSame(555_000, $retract['chat_id']);
        $this->assertSame(321, $retract['message_id']);
        $this->assertDatabaseMissing('telegram_connections', ['id' => $id]);
    }

    public function test_retract_job_skips_verified_connections(): void
    {
        $connection = $this->pendingInvite([
            'status' => 'connected',
            'target_user_id' => 555_000,
            'metadata' => ['provisioning' => 'invite', 'dm_chat_id' => 555_000, 'dm_message_id' => 321],
        ]);
        $id = $connection->id;

        (new RetractInviteCode($id))->handle($this->peer);

        $this->assertSame([], $this->peer->calls);
        $this->assertDatabaseHas('telegram_connections', ['id' => $id]);
    }

    public function test_anonymous_roxi_code_starts_confirmation_for_any_sender_without_connecting(): void
    {
        Queue::fake();
        $service = app(RoxiProvisioningService::class);
        $connection = TelegramConnection::factory()->create([
            'method' => 'roxi',
            'status' => 'pending',
        ]);
        $code = $service->issueCode($connection);

        $matched = $service->handleIncomingCode($code, 999_999, [
            'chat_id' => 999_999,
            'chat_type' => 'user',
            'chat_title' => 'Anyone',
            'chat_username' => 'anyone',
        ]);

        $this->assertNotNull($matched);
        $this->assertSame('pending', $matched->status->value);
        $this->assertNull($matched->chat_id);
        $this->assertSame('awaiting_confirmation_delivery', $matched->metadata['provisioning_state']);
        $this->assertNotSame($code, $matched->provision_code_encrypted);
        Queue::assertPushed(DeliverRoxiConfirmationCode::class);
    }
}
