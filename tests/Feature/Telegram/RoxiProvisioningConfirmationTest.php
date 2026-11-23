<?php

namespace Tests\Feature\Telegram;

use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Drivers\FakeTelegramPeerClient;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Jobs\DeliverRoxiConfirmationCode;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Services\RoxiProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class RoxiProvisioningConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_roxi_code_waits_for_web_confirmation_before_committing_chat(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/telegram/connections/roxi', ['name' => 'Roxi storage']);
        $response->assertCreated();
        $connection = TelegramConnection::findOrFail($response->json('connection.id'));

        $matched = app(RoxiProvisioningService::class)->handleIncomingCode($response->json('code'), 555_000, [
            'chat_id' => 555_000,
            'chat_type' => 'user',
            'chat_title' => 'Alice',
            'chat_username' => 'alice',
        ]);

        $this->assertSame($connection->id, $matched?->id);
        $this->assertSame(TelegramConnectionStatus::Pending, $matched->status);
        $this->assertNull($matched->chat_id);
        $this->assertSame('awaiting_confirmation_delivery', $matched->metadata['provisioning_state']);
        $this->assertNotSame($response->json('code'), $matched->provision_code_encrypted);
        Queue::assertPushedOn('telegram', DeliverRoxiConfirmationCode::class);
        $this->actingAs($user)->get('/telegram/connections')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('connections.0.provisioning_state', 'awaiting_confirmation_delivery')
            ->missing('connections.0.chat_id'));

        $peerClient = new FakeTelegramPeerClient;
        $attemptId = $matched->metadata['provisioning_attempt_id'];
        (new DeliverRoxiConfirmationCode($connection->id, $attemptId))->handle($peerClient, app(RoxiProvisioningService::class));

        $delivered = $connection->fresh();
        $this->assertSame('awaiting_confirmation', $delivered->metadata['provisioning_state']);
        $this->assertNull($delivered->provision_code_encrypted);
        $this->assertSame(555_000, $peerClient->calls[0]['chat_id']);
        $this->actingAs($user)->get('/telegram/connections')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('connections.0.provisioning_state', 'awaiting_confirmation'));
        preg_match('/confirmation code: (ROXI-[A-Z0-9]{4}-[A-Z0-9]{4})/i', $peerClient->calls[0]['text'], $matches);
        $confirmationCode = $matches[1];
        $this->assertNotSame($response->json('code'), $confirmationCode);

        $this->assertNull(app(RoxiProvisioningService::class)->handleIncomingCode($response->json('code'), 555_000, [
            'chat_id' => 555_000,
            'chat_type' => 'user',
            'chat_title' => 'Alice',
            'chat_username' => 'alice',
        ]));
        $this->assertNull(app(RoxiProvisioningService::class)->handleIncomingCode($confirmationCode, 555_000, [
            'chat_id' => 555_000,
            'chat_type' => 'user',
            'chat_title' => 'Alice',
            'chat_username' => 'alice',
        ]));
        $this->assertSame(TelegramConnectionStatus::Pending, $connection->fresh()->status);
        $this->assertNull($connection->fresh()->chat_id);

        $this->actingAs(User::factory()->create())->postJson('/telegram/connections/'.$connection->id.'/provisioning-confirmation', ['code' => $confirmationCode])
            ->assertForbidden();
        $this->assertSame(TelegramConnectionStatus::Pending, $connection->fresh()->status);

        $this->actingAs($user)->postJson('/telegram/connections/'.$connection->id.'/provisioning-confirmation', ['code' => 'ROXI-AAAA-BBBB'])
            ->assertUnprocessable();
        $this->assertSame('awaiting_confirmation', $connection->fresh()->metadata['provisioning_state']);
        $this->assertNull($connection->fresh()->chat_id);

        $this->actingAs($user)->postJson('/telegram/connections/'.$connection->id.'/provisioning-confirmation', ['code' => ' '.$confirmationCode.' '])
            ->assertOk()->assertJsonPath('confirmed', true);

        $connection->refresh();
        $this->assertSame(TelegramConnectionStatus::Connected, $connection->status);
        $this->assertSame(555_000, $connection->chat_id);
        $this->assertSame('confirmed', $connection->metadata['provisioning_state']);
        $this->assertNull($connection->provision_code_hash);
        $this->assertNull($connection->provision_code_expires_at);
        $this->actingAs($user)->get('/telegram/connections')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('connections.0.provisioning_state', 'confirmed'));

        $this->actingAs($user)->postJson('/telegram/connections/'.$connection->id.'/provisioning-confirmation', ['code' => $confirmationCode])
            ->assertUnprocessable();
        $this->assertDatabaseCount('telegram_connections', 1);
    }

    public function test_expired_confirmation_can_be_retried_without_changing_an_unrelated_connection(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $target = $this->startRoxi($user, 'Target');
        $unrelated = $this->startRoxi($user, 'Unrelated');
        $confirmation = $this->deliverConfirmation($target['connection'], $target['code']);
        $unrelatedHash = $unrelated['connection']->provision_code_hash;

        $this->travel(6)->minutes();
        $this->actingAs($user)->postJson('/telegram/connections/'.$target['connection']->id.'/provisioning-confirmation', [
            'code' => $confirmation['code'],
        ])->assertUnprocessable();

        $target['connection']->refresh();
        $unrelated['connection']->refresh();
        $this->assertSame('expired', $target['connection']->metadata['provisioning_state']);
        $this->assertNull($target['connection']->chat_id);
        $this->assertSame(TelegramConnectionStatus::Pending, $unrelated['connection']->status);
        $this->assertSame('awaiting_code', $unrelated['connection']->metadata['provisioning_state']);
        $this->assertSame($unrelatedHash, $unrelated['connection']->provision_code_hash);

        $response = $this->actingAs($user)->postJson('/telegram/connections/'.$target['connection']->id.'/provisioning-code')->assertOk();

        $target['connection']->refresh();
        $this->assertNotSame($target['code'], $response->json('code'));
        $this->assertSame('awaiting_code', $target['connection']->metadata['provisioning_state']);
        $this->assertNull($target['connection']->metadata['provisioning_chat_id'] ?? null);
        $this->assertSame($unrelatedHash, $unrelated['connection']->refresh()->provision_code_hash);

        (new DeliverRoxiConfirmationCode($target['connection']->id, $confirmation['attempt_id']))
            ->handle($confirmation['peer'], app(RoxiProvisioningService::class));
        $this->assertCount(1, $confirmation['peer']->calls);
    }

    public function test_terminal_confirmation_delivery_failure_is_visible_and_can_be_reissued(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $started = $this->startRoxi($user, 'Roxi storage');
        $service = app(RoxiProvisioningService::class);
        $matched = $service->handleIncomingCode($started['code'], 555_001, [
            'chat_id' => 555_001,
            'chat_type' => 'user',
            'chat_title' => 'Bob',
            'chat_username' => 'bob',
        ]);
        $job = new DeliverRoxiConfirmationCode($matched->id, $matched->metadata['provisioning_attempt_id']);
        $peer = new FakeTelegramPeerClient;
        $peer->throwOnSend = new \RuntimeException('telegram unavailable');

        try {
            $job->handle($peer, $service);
        } catch (\RuntimeException) {
            $job->failed(new \RuntimeException('retries exhausted'));
        }

        $started['connection']->refresh();
        $this->assertSame(TelegramConnectionStatus::Pending, $started['connection']->status);
        $this->assertSame('failed', $started['connection']->metadata['provisioning_state']);
        $this->assertNull($started['connection']->chat_id);
        $this->assertNull($started['connection']->provision_code_hash);
        $this->actingAs($user)->get('/telegram/connections')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('connections.0.provisioning_state', 'failed'));

        $response = $this->actingAs($user)->postJson('/telegram/connections/'.$started['connection']->id.'/provisioning-code')->assertOk();
        $this->assertNotSame($started['code'], $response->json('code'));
        $this->assertSame('awaiting_code', $started['connection']->fresh()->metadata['provisioning_state']);
    }

    public function test_expired_confirmation_delivery_is_not_reported_as_failed(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $started = $this->startRoxi($user, 'Roxi storage');
        $matched = app(RoxiProvisioningService::class)->handleIncomingCode($started['code'], 555_001, [
            'chat_id' => 555_001,
            'chat_type' => 'user',
            'chat_title' => 'Bob',
            'chat_username' => 'bob',
        ]);
        $job = new DeliverRoxiConfirmationCode($matched->id, $matched->metadata['provisioning_attempt_id']);

        $this->travel(6)->minutes();
        $job->failed(new \RuntimeException('retries exhausted'));

        $started['connection']->refresh();
        $this->assertSame(TelegramConnectionStatus::Pending, $started['connection']->status);
        $this->assertSame('expired', $started['connection']->metadata['provisioning_state']);
        $this->assertNull($started['connection']->chat_id);
        $this->assertNull($started['connection']->provision_code_hash);
    }

    public function test_two_pending_confirmations_cannot_claim_the_same_telegram_chat(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $first = $this->startRoxi($user, 'First');
        $second = $this->startRoxi($user, 'Second');
        $firstConfirmation = $this->deliverConfirmation($first['connection'], $first['code']);
        $secondConfirmation = $this->deliverConfirmation($second['connection'], $second['code']);

        $this->actingAs($user)->postJson('/telegram/connections/'.$first['connection']->id.'/provisioning-confirmation', [
            'code' => $firstConfirmation['code'],
        ])->assertOk();
        $this->actingAs($user)->postJson('/telegram/connections/'.$second['connection']->id.'/provisioning-confirmation', [
            'code' => $secondConfirmation['code'],
        ])->assertUnprocessable();

        $this->assertSame(TelegramConnectionStatus::Connected, $first['connection']->fresh()->status);
        $this->assertSame(555_000, $first['connection']->fresh()->chat_id);
        $this->assertSame(TelegramConnectionStatus::Pending, $second['connection']->fresh()->status);
        $this->assertSame('failed', $second['connection']->fresh()->metadata['provisioning_state']);
        $this->assertNull($second['connection']->fresh()->chat_id);
        $this->assertDatabaseCount('telegram_connections', 2);
    }

    public function test_expired_first_code_is_reported_and_can_be_reissued(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $started = $this->startRoxi($user, 'Roxi storage');
        $this->travel(16)->minutes();

        $expired = app(RoxiProvisioningService::class)->handleIncomingCode($started['code'], 555_000, [
            'chat_id' => 555_000,
            'chat_type' => 'user',
            'chat_title' => 'Alice',
            'chat_username' => 'alice',
        ]);

        $this->assertSame('expired', $expired->metadata['provisioning_state']);
        $this->assertNull($expired->chat_id);
        $response = $this->actingAs($user)->postJson('/telegram/connections/'.$started['connection']->id.'/provisioning-code')->assertOk();
        $this->assertNotSame($started['code'], $response->json('code'));
        $this->assertSame('awaiting_code', $started['connection']->fresh()->metadata['provisioning_state']);
    }

    /**
     * @return array{connection: TelegramConnection, code: string}
     */
    private function startRoxi(User $user, string $name): array
    {
        $response = $this->actingAs($user)->postJson('/telegram/connections/roxi', ['name' => $name])->assertCreated();

        return [
            'connection' => TelegramConnection::findOrFail($response->json('connection.id')),
            'code' => $response->json('code'),
        ];
    }

    /**
     * @return array{attempt_id: string, code: string, peer: FakeTelegramPeerClient}
     */
    private function deliverConfirmation(TelegramConnection $connection, string $code): array
    {
        $service = app(RoxiProvisioningService::class);
        $matched = $service->handleIncomingCode($code, 555_000, [
            'chat_id' => 555_000,
            'chat_type' => 'user',
            'chat_title' => 'Alice',
            'chat_username' => 'alice',
        ]);
        $attemptId = $matched->metadata['provisioning_attempt_id'];
        $peer = new FakeTelegramPeerClient;

        (new DeliverRoxiConfirmationCode($connection->id, $attemptId))->handle($peer, $service);
        preg_match('/confirmation code: (ROXI-[A-Z0-9]{4}-[A-Z0-9]{4})/i', $peer->calls[0]['text'], $matches);

        return ['attempt_id' => $attemptId, 'code' => $matches[1], 'peer' => $peer];
    }
}
