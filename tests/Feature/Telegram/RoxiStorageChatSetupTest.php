<?php

namespace Tests\Feature\Telegram;

use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Actions\SetupRoxiStorageChat;
use App\Domains\Telegram\Actions\VerifyTelegramConnection;
use App\Domains\Telegram\Contracts\TelegramPeerClient;
use App\Domains\Telegram\Drivers\FakeTelegramPeerClient;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Jobs\RunRoxiStorageChatSetup;
use App\Domains\Telegram\Jobs\VerifyTelegramConnectionJob;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\RoxiRuntime;
use App\Domains\Telegram\Services\RoxiProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RoxiStorageChatSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_requires_an_authenticated_connection_owner(): void
    {
        $connection = $this->connection();
        Queue::fake([RunRoxiStorageChatSetup::class]);
        $url = $this->url($connection);

        $this->postJson($url, ['mode' => 'discover'])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson($url, ['mode' => 'discover'])->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertArrayNotHasKey('chat_setup', $connection->fresh()->metadata);
    }

    public function test_setup_rejects_invalid_modes_and_unverified_identities(): void
    {
        $connection = $this->connection();
        Queue::fake([RunRoxiStorageChatSetup::class]);
        $this->actingAs($connection->user)->postJson($this->url($connection), ['mode' => 'arbitrary'])->assertUnprocessable()->assertJsonValidationErrors('mode');
        $connection->forceFill(['metadata' => ['storage_mode' => 'existing_chat']])->save();

        $this->postJson($this->url($connection), ['mode' => 'create'])->assertUnprocessable()
            ->assertJsonPath('message', 'Verify your Telegram identity before setting up a storage chat.');

        Queue::assertNothingPushed();
    }

    #[TestWith(['connected'])]
    #[TestWith(['disabled'])]
    #[TestWith(['reconnecting'])]
    public function test_setup_cannot_replace_an_existing_chat_or_run_for_unavailable_connections(string $status): void
    {
        $connection = $this->connection();
        $connection->forceFill(['status' => $status, 'chat_id' => $status === 'connected' ? -1000000123456 : null])->save();
        Queue::fake([RunRoxiStorageChatSetup::class]);

        $this->actingAs($connection->user)->postJson($this->url($connection), ['mode' => 'create'])->assertUnprocessable();

        Queue::assertNothingPushed();
    }

    public function test_discover_returns_only_unclaimed_chats_with_permissions_for_the_verified_user_and_roxi(): void
    {
        $connection = $this->connection();
        TelegramConnection::factory()->connected()->create(['telegram_account_id' => $connection->telegram_account_id, 'chat_id' => -1000000123457]);
        TelegramConnection::factory()->create(['telegram_account_id' => $connection->telegram_account_id, 'metadata' => ['created_chat_id' => -1000000123460]]);
        $runtime = $this->partialMock(RoxiRuntime::class);
        $runtime->shouldReceive('getDialogIds')->once()->andReturn([12345, -1000000123456, -1000000123457, -1000000123458, -1000000123459, -44, -1000000123460]);
        $runtime->shouldReceive('getFullInfo')->with(-1000000123456)->once()->andReturn(['type' => 'channel', 'Chat' => ['title' => 'My channel']]);
        $runtime->shouldReceive('getFullInfo')->with(-1000000123458)->once()->andReturn(['type' => 'channel', 'Chat' => ['title' => 'Secret other user channel']]);
        $runtime->shouldReceive('getFullInfo')->with(-1000000123459)->once()->andReturn(['type' => 'channel', 'Chat' => ['title' => 'No Roxi permissions']]);
        $runtime->shouldReceive('getFullInfo')->with(-44)->once()->andReturn(['type' => 'chat', 'Chat' => ['title' => 'My group'], 'full' => ['participants' => ['participants' => [
            ['_' => 'chatParticipantCreator', 'user_id' => 12345], ['_' => 'chatParticipantAdmin', 'user_id' => 56789],
        ]]]]);
        $runtime->shouldReceive('getInfo')->with('me')->once()->andReturn(['bot_api_id' => 56789]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('messages.getFullChat', ['chat_id' => 44])->once()->andReturn(['full_chat' => ['participants' => ['participants' => [
            ['_' => 'chatParticipantCreator', 'user_id' => 12345], ['_' => 'chatParticipantAdmin', 'user_id' => 56789],
        ]]]]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.getParticipant', ['channel' => -1000000123456, 'participant' => 12345])->once()->andReturn(['participant' => ['_' => 'channelParticipantCreator']]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.getParticipant', ['channel' => -1000000123456, 'participant' => 'me'])->once()->andReturn(['participant' => ['_' => 'channelParticipantCreator']]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.getParticipant', ['channel' => -1000000123458, 'participant' => 12345])->once()->andReturn(['participant' => ['_' => 'channelParticipant']]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.getParticipant', ['channel' => -1000000123459, 'participant' => 12345])->once()->andReturn(['participant' => ['_' => 'channelParticipantAdmin']]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.getParticipant', ['channel' => -1000000123459, 'participant' => 'me'])->once()->andReturn(['participant' => ['_' => 'channelParticipant']]);
        $runtime->shouldNotReceive('uploadFromCallable');

        $this->actingAs($connection->user)->postJson($this->url($connection), ['mode' => 'discover'])->assertAccepted();

        $this->assertNull($connection->fresh()->chat_id);
        $this->assertSame(TelegramConnectionStatus::Pending, $connection->fresh()->status);
        $this->get('/telegram/connections')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('connections', 1)
            ->where('connections.0.chat_setup.status', 'complete')
            ->where('connections.0.chat_setup.chats', [
                ['id' => -1000000123456, 'title' => 'My channel', 'type' => 'channel'],
                ['id' => -44, 'title' => 'My group', 'type' => 'group'],
            ]))->assertDontSee('Secret other user channel');
    }

    public function test_discovery_failure_is_sanitized_and_does_not_activate_storage(): void
    {
        $connection = $this->connection();
        $runtime = $this->mock(RoxiRuntime::class);
        $runtime->shouldReceive('getDialogIds')->once()->andThrow(new \RuntimeException('Discovery failed token=secret-value'));

        $this->actingAs($connection->user)->postJson($this->url($connection), ['mode' => 'discover'])->assertAccepted();

        $this->assertSame('failed', $connection->fresh()->metadata['chat_setup']['status']);
        $this->assertStringNotContainsString('secret-value', $connection->fresh()->metadata['chat_setup']['error']);
        $this->assertNull($connection->fresh()->chat_id);
    }

    public function test_discover_with_no_eligible_chats_completes_without_choosing_a_destination(): void
    {
        $connection = $this->connection();
        $runtime = $this->mock(RoxiRuntime::class);
        $runtime->shouldReceive('getDialogIds')->once()->andReturn([]);

        $this->actingAs($connection->user)->postJson($this->url($connection), ['mode' => 'discover'])->assertAccepted();

        $this->assertSame('complete', $connection->fresh()->metadata['chat_setup']['status']);
        $this->assertSame([], $connection->fresh()->metadata['chat_setup']['chats']);
        $this->assertNull($connection->fresh()->chat_id);
    }

    public function test_selecting_a_discovered_chat_rechecks_current_permissions(): void
    {
        $connection = $this->connection();
        $runtime = $this->partialMock(RoxiRuntime::class);
        $runtime->shouldReceive('getDialogIds')->once()->andReturn([-1000000123456]);
        $runtime->shouldReceive('getFullInfo')->with(-1000000123456)->twice()->andReturn(['type' => 'channel', 'Chat' => ['title' => 'My channel']]);
        $runtime->shouldReceive('getInfo')->with(-1000000123456)->once()->andReturn(['bot_api_id' => -1000000123456]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.getParticipant', ['channel' => -1000000123456, 'participant' => 12345])->twice()->andReturn(
            ['participant' => ['_' => 'channelParticipantCreator']], ['participant' => ['_' => 'channelParticipant']],
        );
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.getParticipant', ['channel' => -1000000123456, 'participant' => 'me'])->once()->andReturn(['participant' => ['_' => 'channelParticipantCreator']]);
        $runtime->shouldNotReceive('uploadFromCallable');
        $this->actingAs($connection->user)->postJson($this->url($connection), ['mode' => 'discover'])->assertAccepted();
        $this->assertCount(1, $connection->fresh()->metadata['chat_setup']['chats']);

        $this->postJson('/telegram/connections/'.$connection->id.'/verify', ['chat_ref' => '-1000000123456'])->assertAccepted();

        $this->assertSame(TelegramConnectionStatus::Error, $connection->fresh()->status);
        $this->assertNull($connection->fresh()->chat_id);
        $this->assertStringContainsString('You must own or administer', $connection->fresh()->last_error);
    }

    public function test_automatic_creation_is_dispatched_only_after_the_correct_user_verifies_the_code(): void
    {
        $user = User::factory()->create();
        $this->app->instance(TelegramPeerClient::class, new FakeTelegramPeerClient);
        Queue::fake([RunRoxiStorageChatSetup::class]);
        $response = $this->actingAs($user)->postJson('/telegram/connections/invite', ['name' => 'Automatic archive', 'target' => '@target_user', 'setup_mode' => 'create', 'group_name' => 'My archive'])->assertCreated();
        $service = app(RoxiProvisioningService::class);
        $metadata = ['chat_id' => 555000, 'chat_type' => 'private', 'chat_title' => 'Alice', 'chat_username' => 'target_user'];
        $this->assertNull($service->handleIncomingCode($response->json('code'), 99999, $metadata));
        Queue::assertNothingPushed();

        $matched = $service->handleIncomingCode($response->json('code'), 555000, $metadata);

        $this->assertSame('My archive', $matched->metadata['group_name']);
        $this->assertNull($matched->chat_id);
        Queue::assertPushed(RunRoxiStorageChatSetup::class, fn (RunRoxiStorageChatSetup $job): bool => $job->connectionId === $matched->id);
    }

    public function test_created_group_adds_the_user_as_admin_and_activates_only_after_file_validation(): void
    {
        $connection = $this->connection();
        Queue::fake([VerifyTelegramConnectionJob::class]);
        $runtime = $this->creationRuntime($connection);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.getParticipant', ['channel' => -1000000123456, 'participant' => 12345])->times(3)->andReturn(
            ['participant' => []], ['participant' => ['_' => 'channelParticipant']], ['participant' => ['_' => 'channelParticipantAdmin']],
        );
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.inviteToChannel', ['channel' => -1000000123456, 'users' => [12345]])->once()->andReturn([]);
        $this->expectAdminGrant($runtime);
        $runtime->shouldReceive('getFullInfo')->with(-1000000123456)->once()->andReturn(['type' => 'supergroup', 'Chat' => ['title' => 'Renamed archive']]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.getParticipant', ['channel' => -1000000123456, 'participant' => 'me'])->once()->andReturn(['participant' => ['_' => 'channelParticipantCreator']]);
        $this->expectFileTest($runtime);

        $this->actingAs($connection->user)->postJson($this->url($connection), ['mode' => 'create'])->assertAccepted();

        $this->assertSame(-1000000123456, $connection->fresh()->metadata['created_chat_id']);
        $this->assertNull($connection->fresh()->chat_id);
        $this->assertSame(TelegramConnectionStatus::Reconnecting, $connection->fresh()->status);
        Queue::assertPushed(VerifyTelegramConnectionJob::class);
        Queue::pushed(VerifyTelegramConnectionJob::class)->first()->handle(app(VerifyTelegramConnection::class));
        $this->assertSame(TelegramConnectionStatus::Connected, $connection->fresh()->status);
        $this->assertSame(-1000000123456, $connection->fresh()->chat_id);
        $this->assertSame('Renamed archive', $connection->fresh()->chat_title);
    }

    public function test_privacy_restrictions_offer_a_join_link_and_retry_reuses_the_created_group(): void
    {
        $connection = $this->connection();
        Queue::fake([VerifyTelegramConnectionJob::class]);
        $runtime = $this->creationRuntime($connection);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.getParticipant', ['channel' => -1000000123456, 'participant' => 12345])->twice()->andReturn(['participant' => []]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.inviteToChannel', ['channel' => -1000000123456, 'users' => [12345]])->once()->andThrow(new \RuntimeException('USER_PRIVACY_RESTRICTED'));
        $runtime->shouldReceive('methodCallAsyncRead')->with('messages.exportChatInvite', Mockery::on(fn (array $args): bool => $args['peer'] === -1000000123456 && $args['usage_limit'] === 1))->once()->andReturn(['link' => 'https://t.me/+private-group']);

        $this->actingAs($connection->user)->postJson($this->url($connection), ['mode' => 'create'])->assertAccepted();

        $this->assertSame('awaiting_join', $connection->fresh()->metadata['chat_setup']['status']);
        $this->assertNull($connection->fresh()->chat_id);
        $this->get('/telegram/connections')->assertInertia(fn (AssertableInertia $page) => $page->where('connections.0.chat_setup.join_link', 'https://t.me/+private-group'));
        Queue::assertNothingPushed();
        $runtime = $this->partialMock(RoxiRuntime::class);
        $runtime->shouldReceive('getInfo')->with('me')->once()->andReturn(['bot_api_id' => 56789]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.createChannel', Mockery::any())->never();
        $runtime->shouldReceive('methodCallAsyncRead')->with('folders.editPeerFolders', ['folder_peers' => [['_' => 'inputFolderPeer', 'peer' => -1000000123456, 'folder_id' => 1]]])->once()->andReturn([]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.getParticipant', ['channel' => -1000000123456, 'participant' => 12345])->once()->andReturn(['participant' => ['_' => 'channelParticipant']]);
        $this->expectAdminGrant($runtime);

        $this->postJson($this->url($connection), ['mode' => 'create'])->assertAccepted();

        $this->assertSame(-1000000123456, $connection->fresh()->metadata['created_chat_id']);
        $this->assertSame('complete', $connection->fresh()->metadata['chat_setup']['status']);
        Queue::assertPushed(VerifyTelegramConnectionJob::class);
    }

    public function test_create_accepts_an_already_normalized_telegram_supergroup_id(): void
    {
        $connection = $this->connection();
        Queue::fake([VerifyTelegramConnectionJob::class]);

        $runtime = $this->partialMock(RoxiRuntime::class);
        $runtime->shouldReceive('getInfo')->with('me')->once()->andReturn(['bot_api_id' => 56789]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.createChannel', Mockery::on(fn (array $args): bool => $args['title'] === 'roxifile-'.$connection->id))
            ->once()->andReturn(['chats' => [['_' => 'channel', 'megagroup' => true, 'id' => -1000000123456]]]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('folders.editPeerFolders', ['folder_peers' => [['_' => 'inputFolderPeer', 'peer' => -1000000123456, 'folder_id' => 1]]])->once()->andReturn([]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.getParticipant', ['channel' => -1000000123456, 'participant' => 12345])
            ->once()->andReturn(['participant' => ['_' => 'channelParticipant']]);
        $this->expectAdminGrant($runtime);

        $this->actingAs($connection->user)->postJson($this->url($connection), ['mode' => 'create'])->assertAccepted();

        $this->assertSame(-1000000123456, $connection->fresh()->metadata['created_chat_id']);
        $this->assertSame('complete', $connection->fresh()->metadata['chat_setup']['status']);
        Queue::assertPushed(VerifyTelegramConnectionJob::class);
    }

    public function test_retry_recovers_an_unconfirmed_group_instead_of_creating_another(): void
    {
        $connection = $this->connection();
        $connection->forceFill(['metadata' => array_merge($connection->metadata, [
            'created_chat_started_at' => now()->subMinute()->toIso8601String(),
        ])])->save();
        Queue::fake([VerifyTelegramConnectionJob::class]);

        $runtime = $this->partialMock(RoxiRuntime::class);
        $runtime->shouldReceive('getInfo')->with('me')->once()->andReturn(['bot_api_id' => 56789]);
        $runtime->shouldReceive('getDialogIds')->once()->andReturn([-1000000123456, -1000000123457]);
        $runtime->shouldReceive('getInfo')->with(-1000000123456)->once()->andReturn(['type' => 'supergroup', 'Chat' => ['title' => 'roxifile-'.$connection->id, 'megagroup' => true]]);
        $runtime->shouldReceive('getInfo')->with(-1000000123457)->once()->andReturn(['type' => 'supergroup', 'Chat' => ['title' => 'Other storage', 'megagroup' => true]]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.getParticipant', ['channel' => -1000000123456, 'participant' => 'me'])->once()->andReturn(['participant' => ['_' => 'channelParticipantCreator']]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.createChannel', Mockery::any())->never();
        $runtime->shouldReceive('methodCallAsyncRead')->with('folders.editPeerFolders', ['folder_peers' => [['_' => 'inputFolderPeer', 'peer' => -1000000123456, 'folder_id' => 1]]])->once()->andReturn([]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.getParticipant', ['channel' => -1000000123456, 'participant' => 12345])->once()->andReturn(['participant' => ['_' => 'channelParticipant']]);
        $this->expectAdminGrant($runtime);

        $this->actingAs($connection->user)->postJson($this->url($connection), ['mode' => 'create'])->assertAccepted();

        $this->assertSame(-1000000123456, $connection->fresh()->metadata['created_chat_id']);
        $this->assertSame('complete', $connection->fresh()->metadata['chat_setup']['status']);
        Queue::assertPushed(VerifyTelegramConnectionJob::class);
    }

    public function test_retry_does_not_claim_a_matching_group_without_creator_permissions(): void
    {
        $connection = $this->connection();
        $connection->forceFill(['metadata' => array_merge($connection->metadata, [
            'created_chat_started_at' => now()->subMinute()->toIso8601String(),
        ])])->save();
        $runtime = $this->partialMock(RoxiRuntime::class);
        $runtime->shouldReceive('getInfo')->with('me')->once()->andReturn(['bot_api_id' => 56789]);
        $runtime->shouldReceive('getDialogIds')->once()->andReturn([-1000000123456]);
        $runtime->shouldReceive('getInfo')->with(-1000000123456)->once()->andReturn(['type' => 'supergroup', 'Chat' => ['title' => 'roxifile-'.$connection->id, 'megagroup' => true]]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.getParticipant', ['channel' => -1000000123456, 'participant' => 'me'])->once()->andReturn(['participant' => ['_' => 'channelParticipantAdmin']]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.createChannel', Mockery::any())->never();

        $this->actingAs($connection->user)->postJson($this->url($connection), ['mode' => 'create'])->assertAccepted();

        $this->assertSame('failed', $connection->fresh()->metadata['chat_setup']['status']);
        $this->assertNull($connection->fresh()->metadata['created_chat_id'] ?? null);
    }

    public function test_uncertain_creation_is_not_repeated_and_cannot_create_duplicate_groups(): void
    {
        $connection = $this->connection();
        $runtime = $this->partialMock(RoxiRuntime::class);
        $runtime->shouldReceive('getInfo')->with('me')->twice()->andReturn(['bot_api_id' => 56789]);
        $runtime->shouldReceive('getDialogIds')->once()->andReturn([]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.createChannel', Mockery::type('array'))->once()->andThrow(new \RuntimeException('Connection lost while creating group.'));
        $this->actingAs($connection->user)->postJson($this->url($connection), ['mode' => 'create'])->assertAccepted();
        $this->assertSame('failed', $connection->fresh()->metadata['chat_setup']['status']);

        $this->postJson($this->url($connection), ['mode' => 'create'])->assertAccepted();

        $this->assertSame('failed', $connection->fresh()->metadata['chat_setup']['status']);
        $this->assertStringContainsString('previous group creation', $connection->fresh()->metadata['chat_setup']['error']);
        $this->assertNull($connection->fresh()->chat_id);
    }

    public function test_repeated_setup_requests_do_not_dispatch_duplicate_jobs(): void
    {
        $connection = $this->connection();
        Queue::fake([RunRoxiStorageChatSetup::class]);
        $this->actingAs($connection->user)->postJson($this->url($connection), ['mode' => 'create'])->assertAccepted();

        $this->postJson($this->url($connection), ['mode' => 'create'])->assertUnprocessable();

        Queue::assertPushed(RunRoxiStorageChatSetup::class, 1);
    }

    public function test_failed_admin_grant_preserves_the_created_group_without_activating_storage(): void
    {
        $connection = $this->connection();
        Queue::fake([VerifyTelegramConnectionJob::class]);
        $runtime = $this->creationRuntime($connection);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.getParticipant', ['channel' => -1000000123456, 'participant' => 12345])->once()->andReturn(['participant' => ['_' => 'channelParticipant']]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.editAdmin', Mockery::type('array'))->once()->andThrow(new \RuntimeException('Admin promotion failed.'));

        $this->actingAs($connection->user)->postJson($this->url($connection), ['mode' => 'create'])->assertAccepted();

        $this->assertSame(-1000000123456, $connection->fresh()->metadata['created_chat_id']);
        $this->assertSame('failed', $connection->fresh()->metadata['chat_setup']['status']);
        $this->assertNull($connection->fresh()->chat_id);
        Queue::assertNothingPushed();
    }

    public function test_a_disabled_connection_cancels_queued_setup_before_telegram_calls(): void
    {
        $connection = $this->connection();
        Queue::fake([RunRoxiStorageChatSetup::class]);
        $runtime = $this->mock(RoxiRuntime::class);
        $runtime->shouldNotReceive('getInfo');
        $runtime->shouldNotReceive('getDialogIds');
        $this->actingAs($connection->user)->postJson($this->url($connection), ['mode' => 'create'])->assertAccepted();
        $connection->forceFill(['status' => 'disabled'])->save();

        Queue::pushed(RunRoxiStorageChatSetup::class)->first()->handle(app(SetupRoxiStorageChat::class));

        $this->assertSame('cancelled', $connection->fresh()->metadata['chat_setup']['status']);
        $this->assertSame(TelegramConnectionStatus::Disabled, $connection->fresh()->status);
        Queue::assertPushed(RunRoxiStorageChatSetup::class, 1);
    }

    public function test_setup_failure_while_queuing_validation_leaves_the_created_group_retryable(): void
    {
        $connection = $this->connection();
        $connection->forceFill(['status' => 'reconnecting', 'metadata' => array_merge($connection->metadata, [
            'created_chat_id' => -1000000123456,
            'chat_setup' => ['status' => 'complete', 'request_id' => 'setup-request'],
        ])])->save();

        app(SetupRoxiStorageChat::class)->fail($connection->id, 'setup-request', new \RuntimeException('Validation could not be queued.'));

        $this->assertSame(TelegramConnectionStatus::Pending, $connection->fresh()->status);
        $this->assertSame('failed', $connection->fresh()->metadata['chat_setup']['status']);
        $this->assertSame(-1000000123456, $connection->fresh()->metadata['created_chat_id']);
    }

    private function connection(): TelegramConnection
    {
        return TelegramConnection::factory()->invite(12345, 'alice')->create([
            'metadata' => ['storage_mode' => 'existing_chat', 'identity_verified_at' => now()->toIso8601String()],
        ]);
    }

    private function url(TelegramConnection $connection): string
    {
        return '/telegram/connections/'.$connection->id.'/storage-chat';
    }

    private function creationRuntime(TelegramConnection $connection): MockInterface
    {
        $runtime = $this->partialMock(RoxiRuntime::class);
        $runtime->shouldReceive('getInfo')->with('me')->once()->andReturn(['bot_api_id' => 56789]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.createChannel', Mockery::on(fn (array $args): bool => $args['title'] === 'roxifile-'.$connection->id && $args['megagroup'] === true))
            ->once()->andReturn(['chats' => [['_' => 'channel', 'megagroup' => true, 'id' => 123456]]]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('folders.editPeerFolders', [
            'folder_peers' => [['_' => 'inputFolderPeer', 'peer' => -1000000123456, 'folder_id' => 1]],
        ])->once()->andReturn([]);

        return $runtime;
    }

    private function expectAdminGrant(MockInterface $runtime): void
    {
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.editAdmin', Mockery::on(fn (array $args): bool => $args['channel'] === -1000000123456 && $args['user_id'] === 12345 && $args['admin_rights']['change_info'] === true))
            ->once()->andReturn([]);
    }

    private function expectFileTest(MockInterface $runtime): void
    {
        $bytes = '';
        $runtime->shouldReceive('uploadFromCallable')->once()->andReturnUsing(static function (callable $read, int $size) use (&$bytes): array {
            $bytes = $read(0, $size);

            return ['_' => 'inputFile', 'id' => 10];
        });
        $runtime->shouldReceive('methodCallAsyncRead')->with('messages.sendMedia', Mockery::on(fn (array $args): bool => $args['peer'] === -1000000123456))->once()->andReturn(['updates' => [['_' => 'updateNewChannelMessage', 'message' => ['id' => 901]]]]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.getMessages', ['channel' => -1000000123456, 'id' => [901]])->once()->andReturn(['messages' => [['_' => 'message', 'id' => 901, 'media' => ['_' => 'messageMediaDocument']]]]);
        $runtime->shouldReceive('downloadToFile')->once()->andReturnUsing(static function (array $media, string $path) use (&$bytes): void {
            file_put_contents($path, $bytes);
        });
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.deleteMessages', ['channel' => -1000000123456, 'id' => [901]])->once()->andReturn([]);
    }
}
