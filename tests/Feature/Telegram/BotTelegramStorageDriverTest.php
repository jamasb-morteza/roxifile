<?php

namespace Tests\Feature\Telegram;

use App\Domains\Files\Enums\FileOperationType;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Drivers\BotTelegramStorageDriver;
use App\Domains\Telegram\Jobs\TelegramOperationJob;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\OperationLease;
use App\Domains\Telegram\Runtime\TelegramOperationProcessor;
use App\Domains\Telegram\Runtime\TelegramUploadContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Bot storage driver against the Bot API (spec §27), exercised with
 * Http::fake() — no real Telegram traffic in tests.
 */
class BotTelegramStorageDriverTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '110201543:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';

    private User $user;

    private TelegramConnection $connection;

    private File $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $account = TelegramAccount::factory()->create([
            'user_id' => $this->user->id,
            'method' => 'bot',
            'credentials' => self::TOKEN,
        ]);

        $this->connection = TelegramConnection::factory()->connected()->create([
            'user_id' => $this->user->id,
            'telegram_account_id' => $account->id,
            'method' => 'bot',
        ]);

        $this->file = File::factory()->create([
            'user_id' => $this->user->id,
            'telegram_account_id' => $account->id,
            'telegram_connection_id' => $this->connection->id,
            'telegram_chat_id' => $this->connection->chat_id,
            'telegram_message_id' => 777,
            'telegram_file_id' => 'file-id-1',
            'status' => 'uploaded',
        ]);
    }

    public function test_upload_sends_the_document_and_maps_the_result(): void
    {
        Http::fake([
            'api.telegram.org/bot'.self::TOKEN.'/sendDocument' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 4242,
                    'document' => ['file_id' => 'doc-file-id', 'file_unique_id' => 'doc-unique'],
                ],
            ]),
        ]);

        Storage::disk('local')->put('telegram-upload/payload.bin', 'payload');

        $operation = FileOperation::factory()->create(['operation' => FileOperationType::Upload]);
        app(OperationLease::class)->acquire($operation->id);
        $result = app(BotTelegramStorageDriver::class)->upload(
            $operation,
            $this->file,
            TelegramUploadContent::fromPath(Storage::disk('local')->path('telegram-upload/payload.bin'), strlen('payload')),
            fn (int $bytes, int $total): bool => true,
        );

        $this->assertSame(4242, $result->telegramMessageId);
        $this->assertSame('doc-file-id', $result->telegramFileId);
        $this->assertSame('doc-unique', $result->telegramMediaId);

        Http::assertSent(function ($request): bool {
            return str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/sendDocument')
                && str_contains($request->body(), (string) $this->connection->chat_id);
        });
    }

    public function test_download_writes_the_file_to_disk(): void
    {
        Http::fake([
            'api.telegram.org/bot'.self::TOKEN.'/getFile*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'documents/report.pdf'],
            ]),
            'api.telegram.org/file/bot'.self::TOKEN.'/*' => Http::response('pdf-bytes'),
        ]);

        $destination = Storage::disk('local')->path('telegram-download/out.bin');
        app(BotTelegramStorageDriver::class)->download($this->file, $destination);

        $this->assertStringEqualsFile($destination, 'pdf-bytes');
    }

    public function test_download_fails_when_telegram_returns_no_file_path(): void
    {
        Http::fake([
            'api.telegram.org/bot'.self::TOKEN.'/getFile*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->expectException(\RuntimeException::class);

        app(BotTelegramStorageDriver::class)->download($this->file, Storage::disk('local')->path('telegram-download/x.bin'));
    }

    public function test_delete_removes_the_message(): void
    {
        Http::fake([
            'api.telegram.org/bot'.self::TOKEN.'/deleteMessage' => Http::response(['ok' => true]),
        ]);

        app(BotTelegramStorageDriver::class)->delete($this->file);

        Http::assertSent(fn ($request): bool => (int) $request['message_id'] === 777);
    }

    public function test_message_existence_is_unknown_without_a_bot_api_message_lookup(): void
    {
        Http::preventStrayRequests();

        $this->assertNull(app(BotTelegramStorageDriver::class)->exists($this->file));

        Http::assertNothingSent();
    }

    public function test_message_existence_stays_unknown_when_the_file_identifier_is_missing(): void
    {
        Http::preventStrayRequests();
        $this->file->update(['telegram_file_id' => null, 'telegram_media_id' => null]);

        $this->assertNull(app(BotTelegramStorageDriver::class)->exists($this->file));

        Http::assertNothingSent();
    }

    public function test_verify_accepts_a_chat_where_the_bot_is_a_member(): void
    {
        $this->connection->forceFill(['metadata' => ['chat_ref' => '@storage_chat']])->save();

        Http::fake([
            'api.telegram.org/bot'.self::TOKEN.'/getChat' => Http::response([
                'ok' => true,
                'result' => ['id' => -1001234, 'type' => 'supergroup', 'title' => 'Storage', 'username' => 'storage_chat'],
            ]),
            'api.telegram.org/bot'.self::TOKEN.'/getChatMember' => Http::response([
                'ok' => true,
                'result' => ['status' => 'administrator'],
            ]),
        ]);

        $verification = app(BotTelegramStorageDriver::class)->verify($this->connection);

        $this->assertTrue($verification->ok);
        $this->assertSame(-1001234, $verification->chatId);
        $this->assertSame('Storage', $verification->chatTitle);
        $this->assertNull($verification->error);
    }

    public function test_verify_fails_without_a_chat_reference(): void
    {
        $this->connection->update(['chat_id' => null]);
        $verification = app(BotTelegramStorageDriver::class)->verify($this->connection);

        $this->assertFalse($verification->ok);
    }

    public function test_verify_fails_when_the_bot_is_not_a_member(): void
    {
        $this->connection->forceFill(['metadata' => ['chat_ref' => '@storage_chat']])->save();

        Http::fake([
            'api.telegram.org/bot'.self::TOKEN.'/getChat' => Http::response([
                'ok' => true,
                'result' => ['id' => -1001234, 'type' => 'supergroup', 'title' => 'Storage'],
            ]),
            'api.telegram.org/bot'.self::TOKEN.'/getChatMember' => Http::response([
                'ok' => true,
                'result' => ['status' => 'left'],
            ]),
        ]);

        $verification = app(BotTelegramStorageDriver::class)->verify($this->connection);

        $this->assertFalse($verification->ok);
    }

    public function test_expired_tokens_surface_a_safe_error(): void
    {
        Http::fake([
            'api.telegram.org/bot'.self::TOKEN.'/deleteMessage' => Http::response(['ok' => false], 401),
        ]);

        try {
            app(BotTelegramStorageDriver::class)->delete($this->file);
            $this->fail('Expected an exception.');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function test_http_upload_download_and_purge_preserve_the_bot_connection_and_bytes(): void
    {
        $bytes = 'bot roundtrip bytes';
        Http::fake([
            'api.telegram.org/bot'.self::TOKEN.'/sendDocument' => Http::response(['ok' => true, 'result' => [
                'message_id' => 8801, 'document' => ['file_id' => 'roundtrip-id', 'file_unique_id' => 'roundtrip-unique'],
            ]]),
            'api.telegram.org/bot'.self::TOKEN.'/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'documents/roundtrip.txt']]),
            'api.telegram.org/file/bot'.self::TOKEN.'/documents/roundtrip.txt' => Http::response($bytes),
            'api.telegram.org/bot'.self::TOKEN.'/deleteMessage' => Http::response(['ok' => true, 'result' => true]),
        ]);
        $upload = $this->actingAs($this->user)->postJson('/files/upload', [
            'connection_id' => $this->connection->id,
            'file' => UploadedFile::fake()->createWithContent('roundtrip.txt', $bytes),
        ])->assertAccepted();
        $file = File::query()->findOrFail($upload->json('file_id'));
        $this->assertSame($this->connection->id, $file->telegram_connection_id);
        $this->assertSame($this->connection->chat_id, $file->telegram_chat_id);
        $this->assertSame(8801, $file->telegram_message_id);
        $prepared = $this->postJson('/files/'.$file->id.'/prepare-download')->assertAccepted();
        $download = $this->get('/files/'.$file->id.'/download?operation_id='.$prepared->json('operation_id'))->assertOk();
        $this->assertSame($bytes, $download->streamedContent());
        $this->deleteJson('/files/'.$file->id.'/purge')->assertOk();
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/deleteMessage') && $request['message_id'] === 8801);
        $this->assertNull($file->fresh());
    }

    public function test_a_stale_bot_response_cannot_overwrite_the_replacement_owners_metadata(): void
    {
        $operation = FileOperation::factory()->create(['operation' => 'upload']);
        app(OperationLease::class)->acquire($operation->id);
        Storage::disk('local')->put('telegram-upload/stale-bot', 'bytes');
        Http::fake(['api.telegram.org/bot'.self::TOKEN.'/sendDocument' => function () use ($operation) {
            $operation->forceFill(['lease_token' => 'new-owner', 'lease_expires_at' => now()->addMinutes(5), 'metadata' => ['replacement' => true]])->save();

            return Http::response(['ok' => true, 'result' => ['message_id' => 901]]);
        }]);

        try {
            app(BotTelegramStorageDriver::class)->upload(
                $operation,
                $this->file,
                TelegramUploadContent::fromPath(Storage::disk('local')->path('telegram-upload/stale-bot'), strlen('bytes')),
                fn () => null,
            );
            $this->fail('A stale response must not be accepted.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('lease', $error->getMessage());
        }
        $this->assertSame(['replacement' => true], $operation->fresh()->metadata);
    }

    public function test_verification_requires_document_permissions_instead_of_membership_alone(): void
    {
        $chats = Http::fakeSequence('api.telegram.org/bot'.self::TOKEN.'/getChat');
        $members = Http::fakeSequence('api.telegram.org/bot'.self::TOKEN.'/getChatMember');
        $chats->push(['ok' => true, 'result' => ['id' => 555, 'type' => 'supergroup', 'permissions' => ['can_send_documents' => false]]]);
        $members->push(['ok' => true, 'result' => ['status' => 'member']]);
        $chats->push(['ok' => true, 'result' => ['id' => 555, 'type' => 'channel']]);
        $members->push(['ok' => true, 'result' => ['status' => 'administrator']]);
        $chats->push(['ok' => true, 'result' => ['id' => 555, 'type' => 'channel']]);
        $members->push(['ok' => true, 'result' => ['status' => 'administrator', 'can_post_messages' => true]]);

        $driver = app(BotTelegramStorageDriver::class);
        $this->assertFalse($driver->verify($this->connection)->ok);
        $this->assertFalse($driver->verify($this->connection)->ok);
        $this->assertTrue($driver->verify($this->connection)->ok);
    }

    public function test_download_rate_limit_preserves_retry_after_in_the_background_operation(): void
    {
        $this->file->update(['telegram_file_id' => 'limited-file']);
        Http::fake([
            'api.telegram.org/bot'.self::TOKEN.'/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'documents/limited']]),
            'api.telegram.org/file/bot'.self::TOKEN.'/documents/limited' => Http::response(['parameters' => ['retry_after' => 45]], 429),
        ]);
        Queue::fake([TelegramOperationJob::class]);
        $response = $this->actingAs($this->user)->postJson('/files/'.$this->file->id.'/prepare-download')->assertAccepted();
        $operationId = $response->json('operation_id');

        (new TelegramOperationJob($operationId))->handle(app(TelegramOperationProcessor::class));

        $operation = FileOperation::query()->findOrFail($operationId);
        $this->assertSame('retrying', $operation->status->value);
        $this->assertEqualsWithDelta(45, now()->diffInSeconds($operation->available_at, false), 1);
    }

    public function test_a_sent_bot_message_without_a_document_id_needs_review_instead_of_claiming_downloadability(): void
    {
        Http::fake(['api.telegram.org/bot'.self::TOKEN.'/sendDocument' => Http::response(['ok' => true, 'result' => ['message_id' => 9001]])]);
        Queue::fake([TelegramOperationJob::class]);
        $response = $this->actingAs($this->user)->postJson('/files/upload', [
            'connection_id' => $this->connection->id,
            'file' => UploadedFile::fake()->createWithContent('incomplete.txt', 'bytes'),
        ])->assertAccepted();
        $operationId = $response->json('operation_id');

        (new TelegramOperationJob($operationId))->handle(app(TelegramOperationProcessor::class));

        $operation = FileOperation::query()->findOrFail($operationId);
        $this->assertSame('needs_review', $operation->status->value);
        $this->assertSame(9001, $operation->metadata['partial_upload_result']['message_id']);
        $this->assertNotSame('uploaded', $operation->file->status->value);
    }
}
