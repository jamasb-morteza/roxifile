<?php

namespace Tests\Feature\Telegram;

use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Contracts\TelegramPeerClient;
use App\Domains\Telegram\Drivers\FakeTelegramPeerClient;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Jobs\ProvisionInviteConnection;
use App\Domains\Telegram\Jobs\VerifyTelegramConnectionJob;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\RoxiRuntime;
use App\Domains\Telegram\Services\RoxiProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class StorageChatConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_invites_verify_identity_without_connecting_private_message_storage(): void
    {
        Queue::fake([ProvisionInviteConnection::class]);
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/telegram/connections/invite', ['name' => 'Archive', 'target' => '@alice']);
        $response->assertCreated();
        $connection = TelegramConnection::findOrFail($response->json('connection.id'));
        $connection->forceFill(['target_user_id' => 12345])->save();

        $provisioning = app(RoxiProvisioningService::class);
        $metadata = ['chat_id' => 12345, 'chat_type' => 'private', 'chat_title' => 'Alice', 'chat_username' => 'alice'];
        $this->assertNull($provisioning->handleIncomingCode($response->json('code'), 99999, $metadata));
        $verified = $provisioning->handleIncomingCode($response->json('code'), 12345, $metadata);

        $this->assertSame(TelegramConnectionStatus::Pending, $verified->status);
        $this->assertNull($verified->chat_id);
        $this->assertNull($verified->last_verified_at);
        $this->assertNull($verified->provision_code_hash);
        $this->assertNotEmpty($verified->metadata['identity_verified_at']);
        $this->assertNull($provisioning->handleIncomingCode($response->json('code'), 12345, $metadata));
        $this->get('/telegram/connections')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('connections.0.needs_storage_chat', true)
            ->where('connections.0.identity_verified', true));
        $this->postJson('/telegram/connections/'.$connection->id.'/provisioning-code')->assertUnprocessable();
        $this->artisan('telegram:prune-pending-invites')->assertSuccessful();
        $this->assertModelExists($connection);
        Queue::assertPushed(ProvisionInviteConnection::class);
    }

    public function test_storage_validation_requires_authentication_and_connection_ownership(): void
    {
        $connection = $this->verifiedIdentity();
        $url = '/telegram/connections/'.$connection->id.'/verify';
        Queue::fake([VerifyTelegramConnectionJob::class]);

        $this->postJson($url, ['chat_ref' => '@archive'])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson($url, ['chat_ref' => '@archive'])->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertNull($connection->fresh()->chat_id);
    }

    public function test_validation_cannot_skip_identity_verification_or_omit_the_chat(): void
    {
        $connection = $this->verifiedIdentity();
        $connection->forceFill(['metadata' => ['storage_mode' => 'existing_chat']])->save();
        Queue::fake([VerifyTelegramConnectionJob::class]);
        $this->actingAs($connection->user)->postJson('/telegram/connections/'.$connection->id.'/verify', ['chat_ref' => '@archive'])
            ->assertUnprocessable()->assertJsonPath('message', 'Complete the provisioning handshake before verifying this connection.');
        $connection->forceFill(['metadata' => ['storage_mode' => 'existing_chat', 'identity_verified_at' => now()->toIso8601String()]])->save();

        $this->postJson('/telegram/connections/'.$connection->id.'/verify')->assertUnprocessable()
            ->assertJsonPath('message', 'Enter your storage group or channel link.');
        Queue::assertNothingPushed();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_background_verification_preserves_connections_awaiting_user_setup(bool $identityVerified): void
    {
        $connection = $this->verifiedIdentity();
        if (! $identityVerified) {
            $connection->forceFill(['metadata' => ['storage_mode' => 'existing_chat']])->save();
        }
        $runtime = $this->mock(RoxiRuntime::class);
        $runtime->shouldNotReceive('getInfo');
        $runtime->shouldNotReceive('getFullInfo');

        $this->artisan('telegram:verify-stale')->assertSuccessful();

        $this->assertSame(TelegramConnectionStatus::Pending, $connection->fresh()->status);
        $this->assertNull($connection->fresh()->last_error);
    }

    public function test_a_storage_channel_username_is_not_mistaken_for_the_connected_users_username(): void
    {
        $connection = $this->verifiedIdentity();
        $connection->forceFill(['status' => 'connected', 'chat_id' => -1000000123456, 'chat_type' => 'channel', 'chat_username' => 'archive'])->save();
        $account = $connection->account;
        $account->forceFill(['method' => 'roxi', 'is_system' => true])->save();
        Queue::fake([ProvisionInviteConnection::class]);

        $this->actingAs(User::factory()->create())->postJson('/telegram/connections/invite', ['name' => 'New storage', 'target' => '@archive'])->assertCreated();

        Queue::assertPushed(ProvisionInviteConnection::class);
    }

    public function test_one_telegram_identity_can_verify_invites_for_multiple_storage_chats(): void
    {
        $user = User::factory()->create();
        $this->app->instance(TelegramPeerClient::class, new FakeTelegramPeerClient);
        $connections = [];

        foreach (['channel' => -1000000123456, 'group' => -123456] as $type => $chatId) {
            $response = $this->actingAs($user)->postJson('/telegram/connections/invite', ['name' => 'Archive '.$type, 'target' => '@target_user'])->assertCreated();
            $connection = TelegramConnection::findOrFail($response->json('connection.id'));
            $matched = app(RoxiProvisioningService::class)->handleIncomingCode($response->json('code'), 555_000, [
                'chat_id' => 555_000, 'chat_type' => 'private', 'chat_title' => 'Alice', 'chat_username' => 'target_user',
            ]);
            $this->assertSame($connection->id, $matched->id);
            $this->assertSame(TelegramConnectionStatus::Pending, $matched->status);
            $runtime = $this->chatRuntime($connection, $chatId, $type);
            $runtime->shouldReceive('getInfo')->with('@archive')->once()->andReturn(['bot_api_id' => $chatId]);
            $this->expectRoundtrip($runtime, $chatId, $type);

            $this->postJson('/telegram/connections/'.$connection->id.'/verify', ['chat_ref' => '@archive'])->assertAccepted();
            $connections[] = $connection->fresh();
        }

        $this->assertSame($connections[0]->target_user_id, $connections[1]->target_user_id);
        $this->assertSame($connections[0]->telegram_account_id, $connections[1]->telegram_account_id);
        $this->assertNotSame($connections[0]->chat_id, $connections[1]->chat_id);
        foreach ($connections as $connection) {
            $this->assertSame(TelegramConnectionStatus::Connected, $connection->status);
        }
    }

    #[TestWith(['channel'])]
    #[TestWith(['supergroup'])]
    #[TestWith(['group'])]
    public function test_a_chat_connects_only_after_successful_file_upload_download_and_deletion(string $type): void
    {
        $connection = $this->verifiedIdentity();
        $chatId = $type === 'group' ? -123456 : -1000000123456;
        $runtime = $this->chatRuntime($connection, $chatId, $type);
        $runtime->shouldReceive('getInfo')->with('@archive')->once()->andReturn(['bot_api_id' => $chatId]);
        $this->expectRoundtrip($runtime, $chatId, $type);

        $this->actingAs($connection->user)->postJson('/telegram/connections/'.$connection->id.'/verify', ['chat_ref' => '@archive'])->assertAccepted();

        $connection->refresh();
        $this->assertSame(TelegramConnectionStatus::Connected, $connection->status);
        $this->assertSame($chatId, $connection->chat_id);
        $this->assertSame($type, $connection->chat_type->value);
        $this->assertSame('My archive', $connection->chat_title);
        $this->assertNotNull($connection->last_verified_at);
        $this->assertSame([], Storage::disk('local')->allFiles('telegram-download'));
        $this->assertDatabaseCount('files', 0);
    }

    public function test_private_invite_links_resolve_only_when_roxi_is_already_a_member(): void
    {
        $connection = $this->verifiedIdentity();
        $runtime = $this->chatRuntime($connection, -1000000123456, 'channel');
        $runtime->shouldReceive('methodCallAsyncRead')->with('messages.checkChatInvite', ['hash' => 'Private_link'])
            ->once()->andReturn(['_' => 'chatInviteAlready', 'chat' => ['_' => 'channel', 'id' => 123456]]);
        $runtime->shouldReceive('getInfo')->with(['_' => 'channel', 'id' => 123456])->once()->andReturn(['bot_api_id' => -1000000123456]);
        $this->expectRoundtrip($runtime, -1000000123456, 'channel');

        $this->actingAs($connection->user)->postJson('/telegram/connections/'.$connection->id.'/verify', ['chat_ref' => 'https://t.me/+Private_link'])->assertAccepted();

        $this->assertSame(TelegramConnectionStatus::Connected, $connection->fresh()->status);
    }

    public function test_a_link_does_not_cause_roxi_to_join_an_unrecognized_chat(): void
    {
        $connection = $this->verifiedIdentity();
        $runtime = $this->mock(RoxiRuntime::class);
        $runtime->shouldReceive('methodCallAsyncRead')->with('messages.checkChatInvite', ['hash' => 'Private_link'])
            ->once()->andReturn(['_' => 'chatInvite']);
        $runtime->shouldNotReceive('uploadFromCallable');

        $this->actingAs($connection->user)->postJson('/telegram/connections/'.$connection->id.'/verify', ['chat_ref' => 'https://t.me/+Private_link'])->assertAccepted();

        $this->assertSame(TelegramConnectionStatus::Error, $connection->fresh()->status);
        $this->assertStringContainsString('Add Roxi', $connection->fresh()->last_error);
        $this->assertNull($connection->fresh()->chat_id);
    }

    #[TestWith(['user', 'Private messages are not supported'])]
    #[TestWith(['channel', 'You must own or administer'])]
    public function test_private_chats_and_chats_the_verified_user_does_not_administer_are_rejected(string $type, string $error): void
    {
        $connection = $this->verifiedIdentity();
        $runtime = $this->partialMock(RoxiRuntime::class);
        $runtime->shouldReceive('getInfo')->with('@archive')->once()->andReturn(['bot_api_id' => -1000000123456]);
        $runtime->shouldReceive('getFullInfo')->with(-1000000123456)->once()->andReturn(['type' => $type]);
        if ($type === 'channel') {
            $runtime->shouldReceive('methodCallAsyncRead')->with('channels.getParticipant', ['channel' => -1000000123456, 'participant' => 12345])
                ->once()->andReturn(['participant' => ['_' => 'channelParticipant']]);
        }
        $runtime->shouldNotReceive('uploadFromCallable');

        $this->actingAs($connection->user)->postJson('/telegram/connections/'.$connection->id.'/verify', ['chat_ref' => '@archive'])->assertAccepted();

        $this->assertSame(TelegramConnectionStatus::Error, $connection->fresh()->status);
        $this->assertStringContainsString($error, $connection->fresh()->last_error);
        $this->assertNull($connection->fresh()->chat_id);
    }

    #[TestWith(['channelParticipant', [], 'Add Roxi as an administrator'])]
    #[TestWith(['channelParticipantAdmin', ['delete_messages' => true], 'permission to post messages'])]
    #[TestWith(['channelParticipantAdmin', ['post_messages' => true], 'permission to delete messages'])]
    public function test_missing_roxi_permissions_fail_before_any_test_file_is_sent(string $role, array $rights, string $error): void
    {
        $connection = $this->verifiedIdentity();
        $runtime = $this->partialMock(RoxiRuntime::class);
        $runtime->shouldReceive('getInfo')->with('@archive')->andReturn(['bot_api_id' => -1000000123456]);
        $runtime->shouldReceive('getFullInfo')->andReturn(['type' => 'channel']);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.getParticipant', ['channel' => -1000000123456, 'participant' => 12345])->andReturn(['participant' => ['_' => 'channelParticipantCreator']]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('channels.getParticipant', ['channel' => -1000000123456, 'participant' => 'me'])->andReturn(['participant' => ['_' => $role, 'admin_rights' => $rights]]);
        $runtime->shouldNotReceive('uploadFromCallable');

        $this->actingAs($connection->user)->postJson('/telegram/connections/'.$connection->id.'/verify', ['chat_ref' => '@archive'])->assertAccepted();

        $this->assertSame(TelegramConnectionStatus::Error, $connection->fresh()->status);
        $this->assertStringContainsString($error, $connection->fresh()->last_error);
        $this->assertNull($connection->fresh()->chat_id);
    }

    #[TestWith(['corrupt'])]
    #[TestWith(['download'])]
    #[TestWith(['delete'])]
    public function test_failed_file_tests_do_not_activate_storage_and_remove_local_test_files(string $failure): void
    {
        $connection = $this->verifiedIdentity();
        $runtime = $this->chatRuntime($connection, -1000000123456, 'channel');
        $runtime->shouldReceive('getInfo')->with('@archive')->andReturn(['bot_api_id' => -1000000123456]);
        $this->expectRoundtrip($runtime, -1000000123456, 'channel', $failure);

        $this->actingAs($connection->user)->postJson('/telegram/connections/'.$connection->id.'/verify', ['chat_ref' => '@archive'])->assertAccepted();

        $this->assertSame(TelegramConnectionStatus::Error, $connection->fresh()->status);
        $this->assertNull($connection->fresh()->chat_id);
        $this->assertNull($connection->fresh()->last_verified_at);
        $this->assertSame([], Storage::disk('local')->allFiles('telegram-download'));
    }

    public function test_another_connection_cannot_claim_the_same_storage_chat(): void
    {
        $connection = $this->verifiedIdentity();
        TelegramConnection::factory()->connected()->create(['telegram_account_id' => $connection->telegram_account_id, 'chat_id' => -1000000123456]);
        $runtime = $this->chatRuntime($connection, -1000000123456, 'channel');
        $runtime->shouldReceive('getInfo')->with('@archive')->andReturn(['bot_api_id' => -1000000123456]);
        $this->expectRoundtrip($runtime, -1000000123456, 'channel');

        $this->actingAs($connection->user)->postJson('/telegram/connections/'.$connection->id.'/verify', ['chat_ref' => '@archive'])->assertAccepted();

        $this->assertSame(TelegramConnectionStatus::Error, $connection->fresh()->status);
        $this->assertSame('This Telegram chat is already connected.', $connection->fresh()->last_error);
        $this->assertNull($connection->fresh()->chat_id);
    }

    public function test_reverification_uses_the_stable_chat_id_after_a_rename_and_refuses_retargeting(): void
    {
        $connection = $this->verifiedIdentity();
        $connection->forceFill(['chat_id' => -1000000123456, 'chat_type' => 'channel', 'status' => 'connected'])->save();
        $runtime = $this->chatRuntime($connection, -1000000123456, 'channel');
        $runtime->shouldNotReceive('getInfo');
        $this->expectRoundtrip($runtime, -1000000123456, 'channel');

        $this->actingAs($connection->user)->postJson('/telegram/connections/'.$connection->id.'/verify', ['chat_ref' => '@different_chat'])->assertAccepted();

        $this->assertSame(-1000000123456, $connection->fresh()->chat_id);
        $this->assertSame('My archive', $connection->fresh()->chat_title);
        $this->assertSame(TelegramConnectionStatus::Connected, $connection->fresh()->status);
    }

    private function verifiedIdentity(): TelegramConnection
    {
        return TelegramConnection::factory()->invite(12345, 'alice')->create([
            'metadata' => ['storage_mode' => 'existing_chat', 'identity_verified_at' => now()->toIso8601String()],
        ]);
    }

    private function chatRuntime(TelegramConnection $connection, int $chatId, string $type): MockInterface
    {
        $runtime = $this->partialMock(RoxiRuntime::class);
        $info = ['type' => $type === 'group' ? 'chat' : $type, 'Chat' => ['title' => 'My archive']];
        if ($type === 'group') {
            $info['full']['participants']['participants'] = [
                ['_' => 'chatParticipantCreator', 'user_id' => $connection->target_user_id],
                ['_' => 'chatParticipantAdmin', 'user_id' => 56789],
            ];
            $runtime->shouldReceive('getInfo')->with('me')->once()->andReturn(['bot_api_id' => 56789]);
            $runtime->shouldReceive('methodCallAsyncRead')->with('messages.getFullChat', ['chat_id' => -$chatId])->once()
                ->andReturn(['full_chat' => ['participants' => ['participants' => $info['full']['participants']['participants']]]]);
        } else {
            $runtime->shouldReceive('methodCallAsyncRead')->with('channels.getParticipant', ['channel' => $chatId, 'participant' => $connection->target_user_id])
                ->once()->andReturn(['participant' => ['_' => 'channelParticipantCreator']]);
            $runtime->shouldReceive('methodCallAsyncRead')->with('channels.getParticipant', ['channel' => $chatId, 'participant' => 'me'])
                ->once()->andReturn(['participant' => ['_' => 'channelParticipantAdmin', 'admin_rights' => ['post_messages' => true, 'delete_messages' => true]]]);
        }
        $runtime->shouldReceive('getFullInfo')->with($chatId)->once()->andReturn($info);

        return $runtime;
    }

    private function expectRoundtrip(MockInterface $runtime, int $chatId, string $type, ?string $failure = null): void
    {
        $bytes = '';
        $runtime->shouldReceive('uploadFromCallable')->once()->andReturnUsing(function (callable $read, int $size) use (&$bytes): array {
            $bytes = $read(0, $size);
            $this->assertStringStartsWith('RoxiFile storage verification ', $bytes);

            return ['_' => 'inputFile', 'id' => 10];
        });
        $runtime->shouldReceive('methodCallAsyncRead')->with('messages.sendMedia', Mockery::on(fn (array $args): bool => $args['peer'] === $chatId))
            ->once()->andReturn(['updates' => [['_' => $type === 'group' ? 'updateNewMessage' : 'updateNewChannelMessage', 'message' => ['id' => 901]]]]);
        $runtime->shouldReceive('methodCallAsyncRead')->with($type === 'group' ? 'messages.getMessages' : 'channels.getMessages', $type === 'group' ? ['id' => [901]] : ['channel' => $chatId, 'id' => [901]])
            ->once()->andReturn(['messages' => [['_' => 'message', 'id' => 901, 'media' => ['_' => 'messageMediaDocument']]]]);
        $runtime->shouldReceive('downloadToFile')->once()->andReturnUsing(static function (array $media, string $path) use (&$bytes, $failure): void {
            file_put_contents($path, $failure === 'corrupt' ? 'corrupt bytes' : $bytes);
            if ($failure === 'download') {
                throw new \RuntimeException('Test download failed.');
            }
        });
        $delete = $runtime->shouldReceive('methodCallAsyncRead')->with($type === 'group' ? 'messages.deleteMessages' : 'channels.deleteMessages', $type === 'group' ? ['id' => [901], 'revoke' => true] : ['channel' => $chatId, 'id' => [901]])->once();
        if ($failure === 'delete') {
            $delete->andThrow(new \RuntimeException('Test file deletion failed.'));
        } else {
            $delete->andReturn([]);
        }
    }
}
