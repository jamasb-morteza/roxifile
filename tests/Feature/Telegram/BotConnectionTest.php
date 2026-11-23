<?php

namespace Tests\Feature\Telegram;

use App\Domains\Files\Models\File;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Actions\UpdateBotCredentials;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Models\TelegramConnectionAttempt;
use App\Domains\Telegram\Services\BotProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BotConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '110201543:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';

    private const NEW_TOKEN = '998877665:AAFnewtokenCH1vGWJxfSeofSAs0K5PALDsaw';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_rejected_token_returns_422_with_safe_message(): void
    {
        Http::fake([
            'api.telegram.org/bot'.self::TOKEN.'/getMe' => Http::response(['ok' => false], 401),
        ]);

        $response = $this->actingAs($this->user)->postJson('/telegram/connections/bot', [
            'name' => 'My Bot Storage',
            'token' => self::TOKEN,
        ]);

        $response->assertStatus(422);
        $this->assertStringNotContainsString(self::TOKEN, (string) $response->json('message'));
        $this->assertNull(TelegramAccount::query()->where('method', 'bot')->first());
    }

    public function test_creates_pending_bot_connection_with_encrypted_credentials(): void
    {
        Http::fake([
            'api.telegram.org/bot'.self::TOKEN.'/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 110201543, 'is_bot' => true, 'username' => 'my_storage_bot'],
            ]),
        ]);

        $response = $this->actingAs($this->user)->postJson('/telegram/connections/bot', [
            'name' => 'My Bot Storage',
            'token' => self::TOKEN,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('bot_username', 'my_storage_bot');
        $this->assertNotEmpty($response->json('code'));
        $this->assertArrayHasKey('private', $response->json('deep_links'));
        $this->assertArrayHasKey('group', $response->json('deep_links'));

        $account = TelegramAccount::query()->where('method', 'bot')->firstOrFail();
        $this->assertSame($this->user->id, $account->user_id);
        $this->assertNotSame(self::TOKEN, $account->getRawOriginal('credentials'));
        $this->assertSame(self::TOKEN, $account->credentials);

        $connection = TelegramConnection::query()->where('method', 'bot')->firstOrFail();
        $this->assertSame('pending', $connection->status->value);
        $this->assertNotNull($connection->provision_code_hash);
        $this->assertStringStartsWith('BOT-', (string) $response->json('code'));

        // The stored hash must not be the plaintext code.
        $this->assertNotSame($response->json('code'), $connection->provision_code_hash);
        $this->assertSame(
            hash('sha256', (new BotProvisioningService)->normalizeCode((string) $response->json('code'))),
            $connection->provision_code_hash,
        );
        $this->assertTrue($connection->provision_code_expires_at->isFuture());
    }

    public function test_code_is_single_use_and_expired_codes_never_match(): void
    {
        $service = app(BotProvisioningService::class);

        $account = TelegramAccount::factory()->create([
            'user_id' => $this->user->id,
            'method' => 'bot',
        ]);
        $connection = TelegramConnection::factory()->create([
            'user_id' => $this->user->id,
            'telegram_account_id' => $account->id,
            'method' => 'bot',
            'status' => 'pending',
        ]);
        $code = $service->issueCode($connection);

        // Wrong code never matches.
        $this->assertNull($service->handleIncomingCode('BOT-WRNG-CODE', 555, [
            'chat_type' => 'private', 'chat_title' => null, 'chat_username' => null,
        ]));

        // Happy path captures the authoritative chat id.
        $matched = $service->handleIncomingCode($code, 555, [
            'chat_type' => 'private', 'chat_title' => 'Storage', 'chat_username' => 'storage_chat',
        ]);

        $this->assertNotNull($matched);
        $this->assertSame(555, $matched->chat_id);
        $this->assertSame('connected', $matched->status->value);
        $this->assertNull($matched->provision_code_hash);
        $this->assertSame(1, TelegramConnectionAttempt::query()->where('result', 'success')->count());

        // Replay: single-use.
        $this->assertNull($service->handleIncomingCode($code, 556, [
            'chat_type' => 'private', 'chat_title' => null, 'chat_username' => null,
        ]));

        // Expired codes never match.
        $expired = TelegramConnection::factory()->create([
            'user_id' => $this->user->id,
            'telegram_account_id' => $account->id,
            'method' => 'bot',
            'status' => 'pending',
        ]);
        $expiredCode = $service->issueCode($expired);
        $expired->forceFill(['provision_code_expires_at' => now()->subMinute()])->save();

        $this->assertNull($service->handleIncomingCode($expiredCode, 777, [
            'chat_type' => 'private', 'chat_title' => null, 'chat_username' => null,
        ]));
    }

    public function test_verify_resolves_chat_from_api_never_browser_input(): void
    {
        Http::fake([
            'api.telegram.org/bot'.self::TOKEN.'/getChat' => Http::response([
                'ok' => true,
                'result' => ['id' => -100999, 'type' => 'supergroup', 'title' => 'Work Storage', 'username' => 'work_storage'],
            ]),
            'api.telegram.org/bot'.self::TOKEN.'/getChatMember' => Http::response([
                'ok' => true,
                'result' => ['status' => 'administrator'],
            ]),
        ]);

        $account = TelegramAccount::factory()->create([
            'user_id' => $this->user->id,
            'method' => 'bot',
            'credentials' => self::TOKEN,
            'metadata' => ['bot_id' => 110201543],
        ]);
        $connection = TelegramConnection::factory()->create([
            'user_id' => $this->user->id,
            'telegram_account_id' => $account->id,
            'method' => 'bot',
            'status' => 'pending',
            'metadata' => null,
        ]);

        $response = $this->actingAs($this->user)->postJson("/telegram/connections/{$connection->id}/verify", [
            'chat_ref' => '@work_storage',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('connection.status', 'connected');

        $connection->refresh();
        $this->assertSame(-100999, $connection->chat_id);
        $this->assertSame('Work Storage', $connection->chat_title);
        $this->assertSame('supergroup', $connection->chat_type->value);
        $this->assertSame('@work_storage', $connection->metadata['chat_ref']);
        $this->assertSame(1, TelegramConnectionAttempt::query()->where('result', 'success')->where('type', 'verify')->count());
    }

    public function test_verify_fails_when_bot_is_not_a_member(): void
    {
        Http::fake([
            'api.telegram.org/bot'.self::TOKEN.'/getChat' => Http::response([
                'ok' => true,
                'result' => ['id' => -100999, 'type' => 'supergroup', 'title' => 'Work Storage'],
            ]),
            'api.telegram.org/bot'.self::TOKEN.'/getChatMember' => Http::response([
                'ok' => true,
                'result' => ['status' => 'left'],
            ]),
        ]);

        $account = TelegramAccount::factory()->create([
            'user_id' => $this->user->id,
            'method' => 'bot',
            'credentials' => self::TOKEN,
        ]);
        $connection = TelegramConnection::factory()->create([
            'user_id' => $this->user->id,
            'telegram_account_id' => $account->id,
            'method' => 'bot',
            'status' => 'pending',
            'metadata' => ['chat_ref' => '@work_storage'],
        ]);

        $response = $this->actingAs($this->user)->postJson("/telegram/connections/{$connection->id}/verify");

        $response->assertStatus(202);
        $response->assertJsonPath('connection.status', 'error');
        $this->assertSame(1, TelegramConnectionAttempt::query()->where('result', 'failure')->where('type', 'verify')->count());
    }

    public function test_credentials_update_revalidates_the_token(): void
    {
        Http::fake([
            'api.telegram.org/bot'.self::NEW_TOKEN.'/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 998877665, 'is_bot' => true, 'username' => 'renamed_bot'],
            ]),
        ]);

        $account = TelegramAccount::factory()->create([
            'user_id' => $this->user->id,
            'method' => 'bot',
            'credentials' => self::TOKEN,
        ]);
        $connection = TelegramConnection::factory()->create([
            'user_id' => $this->user->id,
            'telegram_account_id' => $account->id,
            'method' => 'bot',
            'status' => 'connected',
        ]);

        $response = $this->actingAs($this->user)->patchJson("/telegram/connections/{$connection->id}/credentials", [
            'token' => self::NEW_TOKEN,
        ]);

        $response->assertStatus(200);
        $this->assertSame(self::NEW_TOKEN, $account->fresh()->credentials);
        $this->assertSame(998877665, $account->fresh()->metadata['bot_id']);
    }

    public function test_credentials_update_rejects_bad_token_without_touching_storage(): void
    {
        Http::fake([
            'api.telegram.org/bot'.self::NEW_TOKEN.'/getMe' => Http::response(['ok' => false], 401),
        ]);

        $account = TelegramAccount::factory()->create([
            'user_id' => $this->user->id,
            'method' => 'bot',
            'credentials' => self::TOKEN,
        ]);
        $connection = TelegramConnection::factory()->create([
            'user_id' => $this->user->id,
            'telegram_account_id' => $account->id,
            'method' => 'bot',
        ]);

        $this->actingAs($this->user)->patchJson("/telegram/connections/{$connection->id}/credentials", [
            'token' => self::NEW_TOKEN,
        ])->assertStatus(422);

        $this->assertSame(self::TOKEN, $account->fresh()->credentials);
    }

    public function test_another_user_cannot_verify_or_update_credentials(): void
    {
        $account = TelegramAccount::factory()->create([
            'user_id' => $this->user->id,
            'method' => 'bot',
        ]);
        $connection = TelegramConnection::factory()->create([
            'user_id' => $this->user->id,
            'telegram_account_id' => $account->id,
            'method' => 'bot',
        ]);

        $intruder = User::factory()->create();

        $this->actingAs($intruder)->postJson("/telegram/connections/{$connection->id}/verify")
            ->assertStatus(403);

        $this->actingAs($intruder)->patchJson("/telegram/connections/{$connection->id}/credentials", [
            'token' => self::NEW_TOKEN,
        ])->assertStatus(403);
    }

    public function test_update_bot_credentials_action_rejects_non_bot_accounts(): void
    {
        $roxi = TelegramAccount::factory()->create([
            'user_id' => $this->user->id,
            'method' => 'roxi',
            'is_system' => false,
        ]);

        try {
            app(UpdateBotCredentials::class)->handle($roxi, self::NEW_TOKEN);
            $this->fail('Expected an HTTP exception for non-bot accounts.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_an_account_with_stored_files_cannot_replace_its_credentials_with_a_different_bot(): void
    {
        Http::fake(['api.telegram.org/bot'.self::NEW_TOKEN.'/getMe' => Http::response(['ok' => true, 'result' => ['id' => 998877665, 'is_bot' => true, 'username' => 'another_bot']])]);
        $account = TelegramAccount::factory()->create(['user_id' => $this->user->id, 'method' => 'bot', 'credentials' => self::TOKEN]);
        $connection = TelegramConnection::factory()->connected()->create(['user_id' => $this->user->id, 'telegram_account_id' => $account->id, 'method' => 'bot']);
        File::factory()->uploaded()->create(['user_id' => $this->user->id, 'telegram_account_id' => $account->id, 'telegram_connection_id' => $connection->id]);

        $this->actingAs($this->user)->patchJson('/telegram/connections/'.$connection->id.'/credentials', ['token' => self::NEW_TOKEN])->assertUnprocessable();

        $this->assertSame(self::TOKEN, $account->fresh()->credentials);
    }

    public function test_replacing_an_empty_accounts_bot_resets_its_polling_cursor(): void
    {
        Http::fake(['api.telegram.org/bot'.self::NEW_TOKEN.'/getMe' => Http::response(['ok' => true, 'result' => ['id' => 998877665]])]);
        $account = TelegramAccount::factory()->create(['user_id' => $this->user->id, 'method' => 'bot', 'credentials' => self::TOKEN, 'bot_update_offset' => 9000, 'bot_polled_at' => now()]);

        app(UpdateBotCredentials::class)->handle($account, self::NEW_TOKEN);

        $this->assertSame(0, $account->fresh()->bot_update_offset);
        $this->assertNull($account->fresh()->bot_polled_at);
    }

    public function test_rotating_the_same_bots_token_preserves_its_cursor_and_ends_superseded_verification(): void
    {
        $token = '110201543:replacement';
        Http::fake(['api.telegram.org/bot'.$token.'/getMe' => Http::response(['ok' => true, 'result' => ['id' => 110201543]])]);
        $account = TelegramAccount::factory()->create(['user_id' => $this->user->id, 'method' => 'bot', 'credentials' => self::TOKEN, 'bot_update_offset' => 9000]);
        $connection = TelegramConnection::factory()->create(['user_id' => $this->user->id, 'telegram_account_id' => $account->id, 'method' => 'bot', 'status' => 'reconnecting', 'metadata' => ['verify_request_id' => 'old']]);

        app(UpdateBotCredentials::class)->handle($account, $token);

        $this->assertSame(9000, $account->fresh()->bot_update_offset);
        $this->assertSame('error', $connection->fresh()->status->value);
        $this->assertSame('Credentials changed. Verify this connection again.', $connection->fresh()->last_error);
        $this->assertNotSame('old', $connection->fresh()->metadata['verify_request_id']);
    }
}
