<?php

namespace Tests\Feature\Telegram;

use App\Domains\Files\Models\File;
use App\Domains\Files\Models\Tag;
use App\Domains\Telegram\Actions\PollBotUpdates;
use App\Domains\Telegram\Actions\ReceiveTelegramImport;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Models\TelegramImportRequest;
use App\Domains\Telegram\Runtime\BotApiException;
use App\Domains\Telegram\Services\BotProvisioningService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotPollingTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = '123:testing';

    public function test_connected_bot_is_polled_without_a_pending_provisioning_attempt(): void
    {
        $account = TelegramAccount::factory()->create(['method' => 'bot', 'credentials' => self::BOT_TOKEN]);
        TelegramConnection::factory()->connected()->create([
            'user_id' => $account->user_id, 'telegram_account_id' => $account->id,
            'method' => 'bot', 'chat_id' => 555, 'target_user_id' => 555,
        ]);
        Http::fake(['api.telegram.org/bot'.self::BOT_TOKEN.'/getUpdates' => Http::response(['ok' => true, 'result' => []])]);

        app(PollBotUpdates::class)->handle();

        $this->assertSame(0, $account->fresh()->bot_update_offset);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/getUpdates'));
    }

    public function test_connected_bot_media_update_prompts_the_verified_owner_and_advances_durable_offset(): void
    {
        $account = TelegramAccount::factory()->create(['method' => 'bot', 'credentials' => self::BOT_TOKEN]);
        $connection = TelegramConnection::factory()->connected()->create([
            'user_id' => $account->user_id, 'telegram_account_id' => $account->id,
            'method' => 'bot', 'chat_id' => 555, 'target_user_id' => 555,
        ]);
        Http::fake([
            'api.telegram.org/bot'.self::BOT_TOKEN.'/getUpdates' => Http::response(['ok' => true, 'result' => [[
                'update_id' => 100,
                'message' => [
                    'message_id' => 10,
                    'from' => ['id' => 555, 'is_bot' => false],
                    'chat' => ['id' => 555, 'type' => 'private'],
                    'document' => ['file_id' => 'owner-file', 'file_unique_id' => 'owner-unique', 'file_name' => 'report.pdf', 'mime_type' => 'application/pdf', 'file_size' => 42],
                    'caption' => 'Keep this caption',
                ],
            ]]]),
            'api.telegram.org/bot'.self::BOT_TOKEN.'/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 501]]),
        ]);

        app(PollBotUpdates::class)->handle();

        $this->assertSame(101, $account->fresh()->bot_update_offset);
        $request = TelegramImportRequest::query()->sole();
        $this->assertSame($connection->id, $request->telegram_connection_id);
        $this->assertSame(555, $request->sender_id);
        $this->assertSame('pending', $request->state);
        $this->assertSame('report.pdf', $request->name);
        $this->assertSame(501, $request->prompt_message_id);
        $this->assertSame(101, $account->fresh()->bot_update_offset);
        Http::assertSent(fn ($sent): bool => str_ends_with($sent->url(), '/sendMessage')
            && $sent['chat_id'] === 555 && $sent['reply_to_message_id'] === 10);
    }

    public function test_media_following_a_provisioning_update_in_the_same_batch_is_not_dropped(): void
    {
        $account = TelegramAccount::factory()->create(['method' => 'bot', 'credentials' => self::BOT_TOKEN]);
        $connection = TelegramConnection::factory()->create([
            'user_id' => $account->user_id, 'telegram_account_id' => $account->id,
            'method' => 'bot', 'status' => 'pending',
        ]);
        $code = app(BotProvisioningService::class)->issueCode($connection);
        Http::fake([
            'api.telegram.org/bot'.self::BOT_TOKEN.'/getUpdates' => Http::response(['ok' => true, 'result' => [
                ['update_id' => 101, 'message' => ['message_id' => 11, 'from' => ['id' => 555], 'chat' => ['id' => 555, 'type' => 'private'],
                    'document' => ['file_id' => 'owner-file', 'file_unique_id' => 'owner-unique', 'file_name' => 'report.pdf', 'mime_type' => 'application/pdf', 'file_size' => 42]]],
                ['update_id' => 100, 'message' => ['message_id' => 10, 'text' => '/start '.$code,
                    'from' => ['id' => 555], 'chat' => ['id' => 555, 'type' => 'private']]],
            ]]),
            'api.telegram.org/bot'.self::BOT_TOKEN.'/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 501]]),
        ]);

        app(PollBotUpdates::class)->handle();

        $this->assertSame('connected', $connection->fresh()->status->value);
        $this->assertSame(555, $connection->fresh()->target_user_id);
        $this->assertSame(102, $account->fresh()->bot_update_offset);
        $request = TelegramImportRequest::query()->sole();
        $this->assertSame(11, $request->source_message_id);
        $this->assertSame($connection->id, $request->telegram_connection_id);
    }

    public function test_connected_bot_owner_reply_copies_and_registers_media_in_its_connection(): void
    {
        $account = TelegramAccount::factory()->create(['method' => 'bot', 'credentials' => self::BOT_TOKEN]);
        $connection = TelegramConnection::factory()->connected()->create([
            'user_id' => $account->user_id, 'telegram_account_id' => $account->id,
            'method' => 'bot', 'chat_id' => 555, 'target_user_id' => 555,
        ]);
        $updates = [
            ['update_id' => 100, 'message' => [
                'message_id' => 10, 'from' => ['id' => 555, 'is_bot' => false], 'chat' => ['id' => 555, 'type' => 'private'],
                'document' => ['file_id' => 'owner-file', 'file_unique_id' => 'owner-unique', 'file_name' => 'report.pdf', 'mime_type' => 'application/pdf', 'file_size' => 42],
            ]],
            ['update_id' => 101, 'message' => [
                'message_id' => 11, 'from' => ['id' => 555, 'is_bot' => false], 'chat' => ['id' => 555, 'type' => 'private'],
                'text' => 'root', 'reply_to_message' => ['message_id' => 501],
            ]],
        ];
        $poll = 0;
        Http::fake([
            'api.telegram.org/bot'.self::BOT_TOKEN.'/getUpdates' => function () use (&$poll, $updates) {
                return Http::response(['ok' => true, 'result' => [$updates[$poll++]]]);
            },
            'api.telegram.org/bot'.self::BOT_TOKEN.'/sendMessage' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 501]])
                ->push(['ok' => true, 'result' => ['message_id' => 502]]),
            'api.telegram.org/bot'.self::BOT_TOKEN.'/copyMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 901]]),
        ]);

        app(PollBotUpdates::class)->handle();
        app(PollBotUpdates::class)->handle();

        $file = File::query()->sole();
        $this->assertSame($connection->id, $file->telegram_connection_id);
        $this->assertSame(901, $file->telegram_message_id);
        $this->assertSame('owner-file', $file->telegram_file_id);
        $this->assertSame('report.pdf', $file->name);
        $this->assertSame('completed', TelegramImportRequest::query()->sole()->state);
        $this->assertSame(102, $account->fresh()->bot_update_offset);
        Http::assertSent(fn ($sent): bool => str_ends_with($sent->url(), '/copyMessage')
            && $sent['chat_id'] === 555 && $sent['from_chat_id'] === 555 && $sent['message_id'] === 10);
    }

    public function test_duplicate_update_after_worker_restart_does_not_create_a_second_prompt(): void
    {
        $account = TelegramAccount::factory()->create(['method' => 'bot', 'credentials' => self::BOT_TOKEN]);
        TelegramConnection::factory()->connected()->create([
            'user_id' => $account->user_id, 'telegram_account_id' => $account->id,
            'method' => 'bot', 'chat_id' => 555, 'target_user_id' => 555,
        ]);
        $update = ['update_id' => 100, 'message' => [
            'message_id' => 10, 'from' => ['id' => 555, 'is_bot' => false], 'chat' => ['id' => 555, 'type' => 'private'],
            'document' => ['file_id' => 'owner-file', 'file_unique_id' => 'owner-unique', 'file_name' => 'report.pdf', 'mime_type' => 'application/pdf', 'file_size' => 42],
        ]];
        Http::fake([
            'api.telegram.org/bot'.self::BOT_TOKEN.'/getUpdates' => Http::sequence()
                ->push(['ok' => true, 'result' => [$update]])
                ->push(['ok' => true, 'result' => [$update]]),
            'api.telegram.org/bot'.self::BOT_TOKEN.'/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 501]]),
        ]);

        app(PollBotUpdates::class)->handle();
        app(PollBotUpdates::class)->handle();

        $this->assertDatabaseCount('telegram_import_requests', 1);
        $this->assertSame(101, $account->fresh()->bot_update_offset);
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')));
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/getUpdates') && (int) $request['offset'] === 101);
    }

    public function test_multiple_connected_bots_keep_imports_and_replies_isolated_by_account(): void
    {
        $first = TelegramAccount::factory()->create(['method' => 'bot', 'credentials' => '111:first']);
        $second = TelegramAccount::factory()->create(['method' => 'bot', 'credentials' => '222:second']);
        $firstConnection = TelegramConnection::factory()->connected()->create([
            'user_id' => $first->user_id, 'telegram_account_id' => $first->id,
            'method' => 'bot', 'chat_id' => 555, 'target_user_id' => 555,
        ]);
        $secondConnection = TelegramConnection::factory()->connected()->create([
            'user_id' => $second->user_id, 'telegram_account_id' => $second->id,
            'method' => 'bot', 'chat_id' => 666, 'target_user_id' => 666,
        ]);
        Http::fake([
            'api.telegram.org/bot111:first/getUpdates' => Http::response(['ok' => true, 'result' => [[
                'update_id' => 100, 'message' => ['message_id' => 10, 'from' => ['id' => 555], 'chat' => ['id' => 555, 'type' => 'private'],
                    'document' => ['file_id' => 'first-file', 'file_unique_id' => 'first-unique', 'file_name' => 'first.pdf', 'mime_type' => 'application/pdf', 'file_size' => 42]],
            ]]]),
            'api.telegram.org/bot222:second/getUpdates' => Http::response(['ok' => true, 'result' => [[
                'update_id' => 200, 'message' => ['message_id' => 20, 'from' => ['id' => 666], 'chat' => ['id' => 666, 'type' => 'private'],
                    'document' => ['file_id' => 'second-file', 'file_unique_id' => 'second-unique', 'file_name' => 'second.pdf', 'mime_type' => 'application/pdf', 'file_size' => 84]],
            ]]]),
            'api.telegram.org/bot111:first/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 501]]),
            'api.telegram.org/bot222:second/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 601]]),
        ]);

        app(PollBotUpdates::class)->handle();
        $this->travel(4)->seconds();
        app(PollBotUpdates::class)->handle();

        $requests = TelegramImportRequest::query()->orderBy('telegram_account_id')->get();
        $this->assertCount(2, $requests);
        $this->assertSame($firstConnection->id, $requests[0]->telegram_connection_id);
        $this->assertSame(555, $requests[0]->chat_id);
        $this->assertSame(501, $requests[0]->prompt_message_id);
        $this->assertSame($secondConnection->id, $requests[1]->telegram_connection_id);
        $this->assertSame(666, $requests[1]->chat_id);
        $this->assertSame(601, $requests[1]->prompt_message_id);
    }

    public function test_bot_import_uses_the_details_conversation_before_registering_the_copy(): void
    {
        $account = TelegramAccount::factory()->create(['method' => 'bot', 'credentials' => self::BOT_TOKEN]);
        $connection = TelegramConnection::factory()->connected()->create([
            'user_id' => $account->user_id, 'telegram_account_id' => $account->id,
            'method' => 'bot', 'chat_id' => 555, 'target_user_id' => 555,
        ]);
        $updates = [
            ['update_id' => 100, 'message' => ['message_id' => 10, 'from' => ['id' => 555], 'chat' => ['id' => 555, 'type' => 'private'],
                'document' => ['file_id' => 'owner-file', 'file_unique_id' => 'owner-unique', 'file_name' => 'report.pdf', 'mime_type' => 'application/pdf', 'file_size' => 42]]],
            ['update_id' => 101, 'message' => ['message_id' => 11, 'from' => ['id' => 555], 'chat' => ['id' => 555, 'type' => 'private'],
                'text' => 'details', 'reply_to_message' => ['message_id' => 501]]],
            ['update_id' => 102, 'message' => ['message_id' => 12, 'from' => ['id' => 555], 'chat' => ['id' => 555, 'type' => 'private'],
                'text' => 'folder /', 'reply_to_message' => ['message_id' => 502]]],
            ['update_id' => 103, 'message' => ['message_id' => 13, 'from' => ['id' => 555], 'chat' => ['id' => 555, 'type' => 'private'],
                'text' => 'tags Summer', 'reply_to_message' => ['message_id' => 503]]],
            ['update_id' => 104, 'message' => ['message_id' => 14, 'from' => ['id' => 555], 'chat' => ['id' => 555, 'type' => 'private'],
                'text' => 'description Keep this file', 'reply_to_message' => ['message_id' => 504]]],
            ['update_id' => 105, 'message' => ['message_id' => 15, 'from' => ['id' => 555], 'chat' => ['id' => 555, 'type' => 'private'],
                'text' => 'confirm', 'reply_to_message' => ['message_id' => 505]]],
        ];
        $poll = 0;
        Http::fake([
            'api.telegram.org/bot'.self::BOT_TOKEN.'/getUpdates' => function () use (&$poll, $updates) {
                return Http::response(['ok' => true, 'result' => [$updates[$poll++]]]);
            },
            'api.telegram.org/bot'.self::BOT_TOKEN.'/sendMessage' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 501]])
                ->push(['ok' => true, 'result' => ['message_id' => 502]])
                ->push(['ok' => true, 'result' => ['message_id' => 503]])
                ->push(['ok' => true, 'result' => ['message_id' => 504]])
                ->push(['ok' => true, 'result' => ['message_id' => 505]])
                ->push(['ok' => true, 'result' => ['message_id' => 506]]),
            'api.telegram.org/bot'.self::BOT_TOKEN.'/copyMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 901]]),
        ]);

        for ($pollIndex = 0; $pollIndex < count($updates); $pollIndex++) {
            app(PollBotUpdates::class)->handle();
        }

        $file = File::query()->sole();
        $this->assertSame($connection->id, $file->telegram_connection_id);
        $this->assertSame('Keep this file', $file->description);
        $this->assertSame(['Summer'], $file->tags()->pluck('name')->all());
        $this->assertModelExists(Tag::query()->where('user_id', $account->user_id)->where('name', 'Summer')->sole());
        $this->assertSame(106, $account->fresh()->bot_update_offset);
        Http::assertSent(fn ($sent): bool => str_ends_with($sent->url(), '/copyMessage') && $sent['message_id'] === 10);
    }

    public function test_bot_owner_cannot_import_foreign_or_encrypted_chat_media(): void
    {
        $account = TelegramAccount::factory()->create(['method' => 'bot', 'credentials' => self::BOT_TOKEN]);
        TelegramConnection::factory()->connected()->create([
            'user_id' => $account->user_id, 'telegram_account_id' => $account->id,
            'method' => 'bot', 'chat_id' => 555, 'target_user_id' => 555,
        ]);
        $encryptedAccount = TelegramAccount::factory()->create(['method' => 'bot', 'credentials' => '456:encrypted']);
        TelegramConnection::factory()->connected()->create([
            'user_id' => $encryptedAccount->user_id, 'telegram_account_id' => $encryptedAccount->id,
            'method' => 'bot', 'chat_id' => 666, 'target_user_id' => 666, 'encryption_mode' => 'contents',
        ]);
        Http::fake([
            'api.telegram.org/bot'.self::BOT_TOKEN.'/getUpdates' => Http::response(['ok' => true, 'result' => [
                ['update_id' => 100, 'message' => ['message_id' => 10, 'from' => ['id' => 999], 'chat' => ['id' => 555, 'type' => 'private'],
                    'document' => ['file_id' => 'foreign-file', 'file_unique_id' => 'foreign-unique', 'file_name' => 'foreign.pdf']]],
            ]]),
            'api.telegram.org/bot456:encrypted/getUpdates' => Http::response(['ok' => true, 'result' => [[
                'update_id' => 200, 'message' => ['message_id' => 11, 'from' => ['id' => 666], 'chat' => ['id' => 666, 'type' => 'private'],
                    'document' => ['file_id' => 'encrypted-file', 'file_unique_id' => 'encrypted-unique', 'file_name' => 'encrypted.pdf']],
            ]]]),
            'api.telegram.org/bot456:encrypted/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 701]]),
        ]);

        app(PollBotUpdates::class)->handle();
        $this->travel(4)->seconds();
        app(PollBotUpdates::class)->handle();

        $this->assertDatabaseCount('telegram_import_requests', 0);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'encrypted') && $request['chat_id'] === 666);
    }

    public function test_group_provisioning_records_the_code_sender_as_the_only_import_owner(): void
    {
        $account = TelegramAccount::factory()->create(['method' => 'bot', 'credentials' => self::BOT_TOKEN]);
        $connection = TelegramConnection::factory()->create([
            'user_id' => $account->user_id, 'telegram_account_id' => $account->id,
            'method' => 'bot', 'status' => 'pending',
        ]);
        $code = app(BotProvisioningService::class)->issueCode($connection);
        Http::fake(['api.telegram.org/bot'.self::BOT_TOKEN.'/getUpdates' => Http::response(['ok' => true, 'result' => [[
            'update_id' => 100, 'message' => [
                'message_id' => 10, 'text' => '/start '.$code, 'from' => ['id' => 555, 'is_bot' => false],
                'chat' => ['id' => -100123, 'type' => 'supergroup', 'title' => 'Files'],
            ],
        ]]])]);

        app(PollBotUpdates::class)->handle();

        $this->assertSame('connected', $connection->fresh()->status->value);
        $this->assertSame(-100123, $connection->fresh()->chat_id);
        $this->assertSame(555, $connection->fresh()->target_user_id);
    }

    public function test_bot_import_expiry_is_visible_and_owner_can_restart_the_conversation(): void
    {
        $this->freezeTime();
        $account = TelegramAccount::factory()->create(['method' => 'bot', 'credentials' => self::BOT_TOKEN]);
        TelegramConnection::factory()->connected()->create([
            'user_id' => $account->user_id, 'telegram_account_id' => $account->id,
            'method' => 'bot', 'chat_id' => 555, 'target_user_id' => 555,
        ]);
        $updates = [
            ['update_id' => 100, 'message' => [
                'message_id' => 10, 'from' => ['id' => 555], 'chat' => ['id' => 555, 'type' => 'private'],
                'document' => ['file_id' => 'owner-file', 'file_unique_id' => 'owner-unique', 'file_name' => 'report.pdf', 'mime_type' => 'application/pdf', 'file_size' => 42],
            ]],
            ['update_id' => 101, 'message' => [
                'message_id' => 11, 'from' => ['id' => 555], 'chat' => ['id' => 555, 'type' => 'private'],
                'text' => 'restart', 'reply_to_message' => ['message_id' => 501],
            ]],
        ];
        $poll = 0;
        Http::fake([
            'api.telegram.org/bot'.self::BOT_TOKEN.'/getUpdates' => function () use (&$poll, $updates) {
                return Http::response(['ok' => true, 'result' => [$updates[$poll++]]]);
            },
            'api.telegram.org/bot'.self::BOT_TOKEN.'/sendMessage' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 501]])
                ->push(['ok' => true, 'result' => ['message_id' => 502]])
                ->push(['ok' => true, 'result' => ['message_id' => 503]]),
        ]);

        app(PollBotUpdates::class)->handle();
        $this->travel(25)->hours();
        app(ReceiveTelegramImport::class)->recover();
        app(PollBotUpdates::class)->handle();

        $request = TelegramImportRequest::query()->sole();
        $this->assertSame('pending', $request->state);
        $this->assertTrue($request->expires_at->isFuture());
        $this->assertSame(503, $request->prompt_message_id);
        $this->assertDatabaseCount('files', 0);
        Http::assertSent(fn ($sent): bool => str_ends_with($sent->url(), '/sendMessage')
            && str_contains((string) $sent['text'], 'expired') && $sent['reply_to_message_id'] === 501);
    }

    public function test_failed_bot_prompt_retries_the_same_update_without_advancing_its_offset(): void
    {
        $account = TelegramAccount::factory()->create(['method' => 'bot', 'credentials' => self::BOT_TOKEN]);
        TelegramConnection::factory()->connected()->create([
            'user_id' => $account->user_id, 'telegram_account_id' => $account->id,
            'method' => 'bot', 'chat_id' => 555, 'target_user_id' => 555,
        ]);
        $update = ['update_id' => 100, 'message' => [
            'message_id' => 10, 'from' => ['id' => 555], 'chat' => ['id' => 555, 'type' => 'private'],
            'document' => ['file_id' => 'owner-file', 'file_unique_id' => 'owner-unique', 'file_name' => 'report.pdf', 'mime_type' => 'application/pdf', 'file_size' => 42],
        ]];
        Http::fake([
            'api.telegram.org/bot'.self::BOT_TOKEN.'/getUpdates' => Http::response(['ok' => true, 'result' => [$update]]),
            'api.telegram.org/bot'.self::BOT_TOKEN.'/sendMessage' => Http::sequence()
                ->push(['ok' => false, 'description' => 'Forbidden: bot cannot send messages'], 403)
                ->push(['ok' => true, 'result' => ['message_id' => 501]]),
        ]);

        try {
            app(PollBotUpdates::class)->handle();
            $this->fail('The rejected prompt should leave the update unacknowledged.');
        } catch (BotApiException $error) {
            $this->assertSame(403, $error->httpStatus);
        }

        $this->assertDatabaseCount('telegram_import_requests', 0);
        $this->assertSame(0, $account->fresh()->bot_update_offset);
        app(PollBotUpdates::class)->handle();

        $request = TelegramImportRequest::query()->sole();
        $this->assertSame(101, $account->fresh()->bot_update_offset);
        $this->assertSame(501, $request->prompt_message_id);
    }

    public function test_later_update_failure_keeps_earlier_media_and_its_offset_committed(): void
    {
        $account = TelegramAccount::factory()->create(['method' => 'bot', 'credentials' => self::BOT_TOKEN]);
        TelegramConnection::factory()->connected()->create([
            'user_id' => $account->user_id, 'telegram_account_id' => $account->id,
            'method' => 'bot', 'chat_id' => 555, 'target_user_id' => 555,
        ]);
        $updates = [
            ['update_id' => 100, 'message' => ['message_id' => 10, 'from' => ['id' => 555], 'chat' => ['id' => 555, 'type' => 'private'],
                'document' => ['file_id' => 'first-file', 'file_unique_id' => 'first-unique', 'file_name' => 'first.pdf', 'mime_type' => 'application/pdf', 'file_size' => 42]]],
            ['update_id' => 101, 'message' => ['message_id' => 11, 'from' => ['id' => 555], 'chat' => ['id' => 555, 'type' => 'private'],
                'document' => ['file_id' => 'second-file', 'file_unique_id' => 'second-unique', 'file_name' => 'second.pdf', 'mime_type' => 'application/pdf', 'file_size' => 84]]],
        ];
        Http::fake([
            'api.telegram.org/bot'.self::BOT_TOKEN.'/getUpdates' => Http::response(['ok' => true, 'result' => $updates]),
            'api.telegram.org/bot'.self::BOT_TOKEN.'/sendMessage' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 501]])
                ->push(['ok' => false, 'description' => 'Forbidden: bot cannot send messages'], 403)
                ->push(['ok' => true, 'result' => ['message_id' => 502]]),
        ]);

        try {
            app(PollBotUpdates::class)->handle();
            $this->fail('The second prompt should fail for this poll.');
        } catch (BotApiException) {
            $this->assertDatabaseCount('telegram_import_requests', 1);
            $this->assertSame(101, $account->fresh()->bot_update_offset);
        }

        app(PollBotUpdates::class)->handle();

        $requests = TelegramImportRequest::query()->orderBy('source_message_id')->get();
        $this->assertCount(2, $requests);
        $this->assertSame(501, $requests[0]->prompt_message_id);
        $this->assertSame(502, $requests[1]->prompt_message_id);
        $this->assertSame(102, $account->fresh()->bot_update_offset);
        $this->assertCount(3, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')));
    }

    public function test_a_start_update_connects_only_the_bot_which_received_the_code_and_advances_the_cursor(): void
    {
        $account = TelegramAccount::factory()->create(['method' => 'bot', 'credentials' => '123:testing']);
        $connection = TelegramConnection::factory()->create([
            'user_id' => $account->user_id, 'telegram_account_id' => $account->id,
            'method' => 'bot', 'status' => 'pending',
        ]);
        $code = app(BotProvisioningService::class)->issueCode($connection);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/bot123:testing/getUpdates' => Http::response([
            'ok' => true, 'result' => [['update_id' => 100, 'message' => [
                'text' => '/start@storage_bot '.$code,
                'from' => ['id' => 555, 'is_bot' => false],
                'chat' => ['id' => 555, 'type' => 'private', 'first_name' => 'Target'],
            ]]],
        ])]);

        app(PollBotUpdates::class)->handle();

        $this->assertSame('connected', $connection->fresh()->status->value);
        $this->assertSame(555, $connection->fresh()->chat_id);
        $this->assertSame(555, $connection->fresh()->target_user_id);
        $this->assertSame(101, $account->fresh()->bot_update_offset);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/getUpdates'));
    }

    public function test_a_code_received_by_another_bot_cannot_connect_the_original_account(): void
    {
        $firstBot = TelegramAccount::factory()->create(['method' => 'bot', 'credentials' => '111:first']);
        $secondBot = TelegramAccount::factory()->create(['method' => 'bot', 'credentials' => '222:second']);
        $first = TelegramConnection::factory()->create(['user_id' => $firstBot->user_id, 'telegram_account_id' => $firstBot->id, 'method' => 'bot', 'status' => 'pending']);
        $second = TelegramConnection::factory()->create(['user_id' => $secondBot->user_id, 'telegram_account_id' => $secondBot->id, 'method' => 'bot', 'status' => 'pending']);
        $service = app(BotProvisioningService::class);
        $service->issueCode($first);
        $code = $service->issueCode($second);
        $update = ['ok' => true, 'result' => [['update_id' => 100, 'message' => ['text' => '/start '.$code, 'chat' => ['id' => 555, 'type' => 'private']]]]];
        Http::fake([
            'api.telegram.org/bot111:first/getUpdates' => Http::response($update),
            'api.telegram.org/bot222:second/getUpdates' => Http::response($update),
        ]);

        app(PollBotUpdates::class)->handle();
        $this->assertSame('pending', $first->fresh()->status->value);
        $this->assertSame('pending', $second->fresh()->status->value);
        app(PollBotUpdates::class)->handle();

        $this->assertSame('pending', $first->fresh()->status->value);
        $this->assertSame('connected', $second->fresh()->status->value);
        $this->assertNull($service->handleIncomingCode($code, 999, ['chat_id' => 999, 'chat_type' => 'private', 'chat_title' => null, 'chat_username' => null], $secondBot->id));
        $this->assertSame(555, $second->fresh()->chat_id);
    }

    public function test_a_poll_response_from_replaced_credentials_cannot_connect_or_advance_the_new_bot(): void
    {
        $account = TelegramAccount::factory()->create(['method' => 'bot', 'credentials' => '111:old']);
        $connection = TelegramConnection::factory()->create(['user_id' => $account->user_id, 'telegram_account_id' => $account->id, 'method' => 'bot', 'status' => 'pending']);
        $code = app(BotProvisioningService::class)->issueCode($connection);
        Http::fake(['api.telegram.org/bot111:old/getUpdates' => function () use ($account, $code): PromiseInterface {
            $account->forceFill(['credentials' => '222:new', 'bot_update_offset' => 0, 'bot_polled_at' => null])->save();

            return Http::response(['ok' => true, 'result' => [['update_id' => 9000, 'message' => ['text' => '/start '.$code, 'chat' => ['id' => 555, 'type' => 'private']]]]]);
        }]);

        app(PollBotUpdates::class)->handle();

        $this->assertSame('pending', $connection->fresh()->status->value);
        $this->assertNull($connection->fresh()->chat_id);
        $this->assertSame(0, $account->fresh()->bot_update_offset);
    }
}
