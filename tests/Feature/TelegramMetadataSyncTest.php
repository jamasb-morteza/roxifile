<?php

namespace Tests\Feature;

use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Files\Models\Tag;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Jobs\TelegramOperationJob;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\RoxiRuntime;
use App\Domains\Telegram\Runtime\TelegramOperationProcessor;
use App\Domains\Telegram\Services\TelegramMetadataProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class TelegramMetadataSyncTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['history'])]
    #[TestWith(['deduplicated_result'])]
    #[TestWith(['duplicate_error'])]
    #[TestWith(['unresolved_duplicate'])]
    public function test_roxi_lost_companion_response_reconciles_identity_and_applies_the_newest_revision(string $recovery): void
    {
        $connection = TelegramConnection::factory()->connected()->create(['method' => 'invite']);
        $file = File::factory()->uploaded()->create(['user_id' => $connection->user_id, 'telegram_connection_id' => $connection->id, 'telegram_message_id' => 90, 'metadata' => ['telegram_source_caption' => str_repeat('🗂', 900)]]);
        Queue::fake([TelegramOperationJob::class]);
        $sends = 0;
        $lookups = 0;
        $originalBody = '';
        $editedBodies = [];
        $runtime = $this->partialMock(RoxiRuntime::class);
        $runtime->shouldReceive('methodCallAsyncRead')->andReturnUsing(function (string $method, array $args) use ($recovery, &$sends, &$lookups, &$originalBody, &$editedBodies): array {
            if ($method === 'messages.sendMessage') {
                $sends++;
                if ($sends === 1) {
                    $originalBody = $args['message'];
                    throw new \RuntimeException('network timeout after Telegram accepted the companion');
                }
                if (in_array($recovery, ['duplicate_error', 'unresolved_duplicate'], true)) {
                    throw new \RuntimeException('RANDOM_ID_DUPLICATE');
                }

                return ['_' => 'updateShortSentMessage', 'id' => 101];
            }
            if ($method === 'messages.getHistory') {
                $lookups++;

                $messages = [
                    ['id' => 201, 'out' => false, 'message' => $originalBody, 'reply_to' => ['reply_to_msg_id' => 90]],
                    ['id' => 202, 'out' => true, 'message' => $originalBody, 'reply_to' => ['reply_to_msg_id' => 91]],
                ];
                if ($recovery !== 'unresolved_duplicate' && ($recovery === 'history' || $lookups > 1)) {
                    $messages[] = ['id' => 101, 'out' => true, 'message' => $originalBody, 'reply_to' => ['reply_to_msg_id' => 90]];
                }

                return ['messages' => $messages];
            }
            $this->assertSame('messages.editMessage', $method);
            $this->assertSame(101, $args['id']);
            $editedBodies[] = $args['message'];

            return [];
        });
        $this->actingAs($connection->user)->patchJson('/files/'.$file->id.'/rename', ['name' => 'Earlier.txt'])->assertOk();
        $first = FileOperation::query()->latest('id')->firstOrFail();
        (new TelegramOperationJob($first->id))->handle(app(TelegramOperationProcessor::class));
        $this->getJson('/file-operations')->assertJsonFragment(['status' => 'retrying']);
        $this->patchJson('/files/'.$file->id.'/rename', ['name' => 'Latest.txt'])->assertOk();
        $latest = FileOperation::query()->latest('id')->firstOrFail();
        (new TelegramOperationJob($latest->id))->handle(app(TelegramOperationProcessor::class));
        $this->travel(10)->seconds();
        (new TelegramOperationJob($first->id))->handle(app(TelegramOperationProcessor::class));

        if ($recovery === 'unresolved_duplicate') {
            $this->assertSame('needs_review', $latest->fresh()->status->value);
            $this->assertSame([], $editedBodies);
            $this->assertSame(2, $sends);

            return;
        }
        $this->assertSame('completed', $latest->fresh()->status->value);
        $this->assertCount(1, $editedBodies);
        $this->assertStringContainsString('Latest.txt', $editedBodies[0]);
        $this->assertStringNotContainsString('Earlier.txt', $editedBodies[0]);
        $this->assertSame($recovery === 'history' ? 1 : 2, $sends);
        $this->assertSame(101, $file->fresh()->metadata['telegram_metadata_companion_id']);
    }

    public function test_description_validation_preserves_existing_metadata_and_allows_clearing(): void
    {
        $file = File::factory()->create(['description' => 'Original description']);
        $this->actingAs($file->user);
        $this->patchJson('/files/'.$file->id.'/description', ['description' => ['invalid']])
            ->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->patchJson('/files/'.$file->id.'/description', ['description' => str_repeat('x', 5001)])
            ->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->assertSame('Original description', $file->fresh()->description);
        $this->patchJson('/files/'.$file->id.'/description', ['description' => null])
            ->assertOk()->assertJsonPath('file.description', null);
        $this->assertNull($file->fresh()->description);
    }

    #[TestWith(['invite'])]
    #[TestWith(['roxi'])]
    public function test_roxi_permission_expiry_creates_one_reply_then_updates_its_saved_identity(string $method): void
    {
        $connection = TelegramConnection::factory()->connected()->create(['method' => $method]);
        $file = File::factory()->uploaded()->create(['user_id' => $connection->user_id, 'telegram_connection_id' => $connection->id, 'telegram_message_id' => 90, 'metadata' => ['telegram_source_caption' => 'Original source']]);
        Queue::fake([TelegramOperationJob::class]);
        $runtime = $this->partialMock(RoxiRuntime::class);
        $runtime->shouldReceive('methodCallAsyncRead')->with('messages.editMessage', \Mockery::on(fn (array $args): bool => $args['id'] === 90 && str_contains($args['message'], 'Original source') && $args['entities'] === []))->once()->andThrow(new \RuntimeException('MESSAGE_EDIT_TIME_EXPIRED'));
        $runtime->shouldReceive('methodCallAsyncRead')->with('messages.sendMessage', \Mockery::on(fn (array $args): bool => $args['reply_to']['reply_to_msg_id'] === 90 && is_int($args['random_id']) && $args['entities'] === []))->once()->andReturn(['_' => 'updateShortSentMessage', 'id' => 101]);
        $runtime->shouldReceive('methodCallAsyncRead')->with('messages.editMessage', \Mockery::on(fn (array $args): bool => $args['id'] === 101 && str_contains($args['message'], 'Latest.txt')))->once()->andReturn([]);
        $this->actingAs($connection->user)->patchJson('/files/'.$file->id.'/rename', ['name' => 'First.txt'])->assertOk();
        $first = FileOperation::query()->latest('id')->firstOrFail();
        (new TelegramOperationJob($first->id))->handle(app(TelegramOperationProcessor::class));
        $this->assertSame('completed', $first->fresh()->status->value);
        $this->patchJson('/files/'.$file->id.'/rename', ['name' => 'Latest.txt'])->assertOk();
        $second = FileOperation::query()->latest('id')->firstOrFail();
        (new TelegramOperationJob($second->id))->handle(app(TelegramOperationProcessor::class));
        $this->assertSame('completed', $second->fresh()->status->value);
    }

    public function test_an_edit_during_transport_is_followed_by_the_latest_projection(): void
    {
        $connection = TelegramConnection::factory()->connected()->create(['method' => 'bot']);
        $connection->account->update(['credentials' => '123:token']);
        $file = File::factory()->uploaded()->create(['user_id' => $connection->user_id, 'telegram_connection_id' => $connection->id, 'telegram_account_id' => $connection->telegram_account_id, 'telegram_message_id' => 90, 'metadata' => ['telegram_metadata_owned_caption' => true]]);
        Queue::fake([TelegramOperationJob::class]);
        $sent = [];
        Http::fake(['api.telegram.org/*/editMessageCaption' => function ($request) use ($file, &$sent) {
            $sent[] = $request['caption'];
            if (count($sent) === 1) {
                $this->patchJson('/files/'.$file->id.'/rename', ['name' => 'Concurrent.txt'])->assertOk();
                $queued = FileOperation::query()->latest('id')->firstOrFail();
                (new TelegramOperationJob($queued->id))->handle(app(TelegramOperationProcessor::class));
            }

            return Http::response(['ok' => true, 'result' => true]);
        }]);
        $this->actingAs($connection->user)->patchJson('/files/'.$file->id.'/rename', ['name' => 'Earlier.txt'])->assertOk();
        $first = FileOperation::query()->latest('id')->firstOrFail();
        (new TelegramOperationJob($first->id))->handle(app(TelegramOperationProcessor::class));
        $latest = FileOperation::query()->latest('id')->firstOrFail();
        $this->getJson('/file-operations')->assertJsonFragment(['status' => 'retrying']);
        $this->travel(10)->seconds();
        (new TelegramOperationJob($latest->id))->handle(app(TelegramOperationProcessor::class));
        (new TelegramOperationJob($first->id))->handle(app(TelegramOperationProcessor::class));
        $this->assertCount(2, $sent);
        $this->assertStringContainsString('Concurrent.txt', $sent[1]);
        $this->assertSame('Concurrent.txt', $file->fresh()->name);
    }

    public function test_long_source_caption_is_left_untouched_and_long_metadata_is_shortened_in_one_companion(): void
    {
        $connection = TelegramConnection::factory()->connected()->create(['method' => 'bot']);
        $connection->account->update(['credentials' => '123:token']);
        $source = str_repeat('🗂', 900);
        $description = str_repeat('🗂Long description ', 900);
        $file = File::factory()->uploaded()->create(['user_id' => $connection->user_id, 'telegram_connection_id' => $connection->id, 'telegram_account_id' => $connection->telegram_account_id, 'telegram_message_id' => 90, 'metadata' => ['telegram_source_caption' => $source]]);
        Queue::fake([TelegramOperationJob::class]);
        Http::fake(['api.telegram.org/*/sendMessage' => function ($request) {
            $this->assertLessThanOrEqual(4096, intdiv(strlen(mb_convert_encoding($request['text'], 'UTF-16LE', 'UTF-8')), 2));
            $this->assertStringContainsString('[Shortened; full metadata in RoxiFile]', $request['text']);
            $this->assertStringContainsString('/files?file=', $request['text']);

            return Http::response(['ok' => true, 'result' => ['message_id' => 101]]);
        }]);
        $this->actingAs($connection->user)->patchJson('/files/'.$file->id.'/description', ['description' => mb_substr($description, 0, 5000)])->assertOk();
        $operation = FileOperation::query()->latest('id')->firstOrFail();
        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));
        Http::assertSentCount(1);
        $this->assertSame($source, $file->fresh()->metadata['telegram_source_caption']);
        $this->assertSame(mb_substr($description, 0, 5000), $file->fresh()->description);
    }

    public function test_network_failure_retains_the_edit_and_owner_can_retry_from_visible_status(): void
    {
        $connection = TelegramConnection::factory()->connected()->create(['method' => 'bot']);
        $connection->account->update(['credentials' => '123:token']);
        $file = File::factory()->uploaded()->create(['user_id' => $connection->user_id, 'telegram_connection_id' => $connection->id, 'telegram_account_id' => $connection->telegram_account_id, 'telegram_message_id' => 90, 'metadata' => ['telegram_metadata_owned_caption' => true]]);
        Queue::fake([TelegramOperationJob::class]);
        config(['telegram.worker.max_attempts' => 1]);
        Http::fake(['api.telegram.org/*/editMessageCaption' => Http::sequence()->push(['ok' => false, 'error_code' => 503, 'description' => 'Temporary outage'], 503)->push(['ok' => true, 'result' => true])]);
        $this->actingAs($connection->user)->patchJson('/files/'.$file->id.'/description', ['description' => 'Persist despite outage'])->assertOk();
        $operation = FileOperation::query()->latest('id')->firstOrFail();
        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));
        $this->getJson('/file-operations')->assertJsonFragment(['operation' => 'metadata', 'status' => 'failed']);
        $this->assertSame('Persist despite outage', $file->fresh()->description);
        $this->assertSame('uploaded', $file->fresh()->status->value);
        Storage::disk('local')->put('telegram-download/ready', 'original stored bytes');
        $download = FileOperation::factory()->create([
            'user_id' => $file->user_id, 'file_id' => $file->id, 'operation' => 'download', 'status' => 'completed',
            'metadata' => ['downloaded_path' => 'telegram-download/ready', 'expires_at' => now()->addMinutes(15)->toIso8601String()],
        ]);
        $this->get('/files/'.$file->id.'/download?operation_id='.$download->id)->assertOk();
        $this->postJson('/file-operations/'.$operation->id.'/cancel')->assertAccepted();
        $this->get('/files/'.$file->id.'/download?operation_id='.$download->id)->assertOk();
        $this->assertSame('uploaded', $file->fresh()->status->value);
        $this->actingAs(User::factory()->create())->postJson('/file-operations/'.$operation->id.'/retry')->assertForbidden();
        $this->actingAs($connection->user)->postJson('/file-operations/'.$operation->id.'/retry')->assertAccepted();
        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));
        $this->getJson('/file-operations')->assertJsonFragment(['operation' => 'metadata', 'status' => 'completed']);
    }

    public function test_unknown_companion_send_is_held_for_review_and_not_duplicated_by_retry_or_new_edits(): void
    {
        $connection = TelegramConnection::factory()->connected()->create(['method' => 'bot']);
        $connection->account->update(['credentials' => '123:token']);
        $file = File::factory()->uploaded()->create(['user_id' => $connection->user_id, 'telegram_connection_id' => $connection->id, 'telegram_account_id' => $connection->telegram_account_id, 'telegram_message_id' => 90, 'metadata' => ['telegram_source_caption' => str_repeat('🗂', 900)]]);
        Queue::fake([TelegramOperationJob::class]);
        Http::fake(['api.telegram.org/*/sendMessage' => Http::failedConnection()]);
        $this->actingAs($connection->user)->patchJson('/files/'.$file->id.'/rename', ['name' => 'First.txt'])->assertOk();
        $operation = FileOperation::query()->latest('id')->firstOrFail();
        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));
        $this->travel(10)->seconds();
        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));
        $this->getJson('/file-operations')->assertJsonFragment(['status' => 'needs_review']);
        $this->patchJson('/files/'.$file->id.'/rename', ['name' => 'Newest.txt'])->assertOk();
        $latest = FileOperation::query()->latest('id')->firstOrFail();
        (new TelegramOperationJob($latest->id))->handle(app(TelegramOperationProcessor::class));
        $this->assertSame('needs_review', $latest->fresh()->status->value);
        Http::assertSentCount(1);
        $this->assertSame(str_repeat('🗂', 900), $file->fresh()->metadata['telegram_source_caption']);
    }

    #[TestWith(['contents'])]
    #[TestWith(['contents_and_metadata'])]
    #[TestWith(['future_mode'])]
    #[TestWith(['none'])]
    public function test_delayed_ordinary_jobs_deny_encrypted_modes_without_readable_fallback(string $mode): void
    {
        $connection = TelegramConnection::factory()->connected()->create(['method' => 'bot']);
        $file = File::factory()->uploaded()->create(['user_id' => $connection->user_id, 'telegram_connection_id' => $connection->id, 'telegram_message_id' => 90, 'metadata' => ['telegram_metadata_owned_caption' => true]]);
        Queue::fake([TelegramOperationJob::class]);
        $this->actingAs($connection->user)->patchJson('/files/'.$file->id.'/rename', ['name' => 'BeforeScopeChange.txt'])->assertOk();
        $operation = FileOperation::query()->latest('id')->firstOrFail();
        $connection->update(['encryption_mode' => $mode]);
        if ($mode === 'none') {
            $file->update(['encryption_header' => 'encrypted-file-header']);
        }
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));
        $this->getJson('/file-operations')->assertJsonFragment(['status' => 'failed']);
        Http::assertNothingSent();
    }

    public function test_connection_owner_can_disable_tag_projection_without_disabling_descriptions(): void
    {
        $connection = TelegramConnection::factory()->connected()->create();
        $file = File::factory()->uploaded()->create([
            'user_id' => $connection->user_id,
            'telegram_connection_id' => $connection->id,
            'description' => 'Keep this description',
        ]);
        $file->tags()->attach(Tag::factory()->create(['user_id' => $connection->user_id, 'name' => 'Private'])->id);
        $url = '/telegram/connections/'.$connection->id;

        $this->actingAs($connection->user)->patchJson($url, ['sync_caption_tags' => false])
            ->assertOk()->assertJsonPath('connection.sync_caption_tags', false);

        $this->withoutVite()->get('/settings/telegram')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Telegram')->where('connections.0.sync_caption_tags', false));
        $this->actingAs(User::factory()->create())->patchJson($url, ['sync_caption_tags' => true])->assertForbidden();

        $caption = app(TelegramMetadataProjection::class)->caption($file->fresh(), 1024);
        $this->assertStringNotContainsString('#Private', $caption);
        $this->assertStringContainsString('Keep this description', $caption);
    }

    public function test_bulk_tags_file_move_and_detach_queue_current_per_file_projections(): void
    {
        $connection = TelegramConnection::factory()->connected()->create(['method' => 'bot']);
        $connection->account->update(['credentials' => '123:token']);
        $file = File::factory()->uploaded()->create(['user_id' => $connection->user_id, 'telegram_connection_id' => $connection->id, 'telegram_account_id' => $connection->telegram_account_id, 'telegram_message_id' => 90, 'metadata' => ['telegram_metadata_owned_caption' => true]]);
        $directory = Directory::factory()->create(['user_id' => $connection->user_id, 'name' => 'Moved']);
        Queue::fake([TelegramOperationJob::class]);
        $this->actingAs($connection->user)->postJson('/files/bulk/tags', ['file_ids' => [$file->id], 'tags' => ['Keep', 'Remove']])->assertOk();
        $tag = $file->tags()->where('name', 'Remove')->firstOrFail();
        $this->deleteJson('/files/'.$file->id.'/tags/'.$tag->id)->assertOk();
        $this->patchJson('/files/'.$file->id.'/move', ['directory_id' => $directory->id])->assertOk();
        $operations = FileOperation::query()->where('operation', 'metadata')->get();
        $this->assertCount(3, $operations);
        Http::fake(['api.telegram.org/*/editMessageCaption' => Http::response(['ok' => true, 'result' => true])]);
        foreach ($operations as $operation) {
            (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));
        }
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['caption'], 'Moved/') && str_contains($request['caption'], '#Keep') && ! str_contains($request['caption'], '#Remove'));
    }

    public function test_folder_and_tag_cascades_project_the_final_path_and_tags(): void
    {
        $connection = TelegramConnection::factory()->connected()->create(['method' => 'bot']);
        $connection->account->update(['credentials' => '123:token']);
        $directory = Directory::factory()->create(['user_id' => $connection->user_id, 'name' => 'Old']);
        $nested = Directory::factory()->create(['user_id' => $connection->user_id, 'name' => 'Nested', 'parent_id' => $directory->id]);
        $file = File::factory()->uploaded()->create(['user_id' => $connection->user_id, 'directory_id' => $nested->id, 'telegram_connection_id' => $connection->id, 'telegram_account_id' => $connection->telegram_account_id, 'telegram_message_id' => 90, 'metadata' => ['telegram_metadata_owned_caption' => true]]);
        Queue::fake([TelegramOperationJob::class]);
        $this->actingAs($connection->user);
        $this->postJson('/files/'.$file->id.'/tags', ['tags' => ['OldTag', 'DeleteMe']])->assertOk();
        $tag = $file->tags()->where('name', 'OldTag')->firstOrFail();
        $deleted = $file->tags()->where('name', 'DeleteMe')->firstOrFail();
        $this->patchJson('/tags/'.$tag->id, ['name' => 'NewestTag'])->assertOk();
        $this->deleteJson('/tags/'.$deleted->id)->assertOk();
        $this->patchJson('/directories/'.$directory->id.'/rename', ['name' => 'NewestFolder'])->assertOk();
        $parent = Directory::factory()->create(['user_id' => $connection->user_id, 'name' => 'Destination']);
        $this->patchJson('/directories/'.$directory->id.'/move', ['parent_id' => $parent->id])->assertOk();
        Http::fake(['api.telegram.org/*/editMessageCaption' => Http::response(['ok' => true, 'result' => true])]);
        $operations = FileOperation::query()->where('operation', 'metadata')->get();
        $this->assertCount(5, $operations);
        foreach ($operations as $operation) {
            (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));
        }
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['caption'], 'Destination/NewestFolder/Nested/') && str_contains($request['caption'], '#NewestTag') && ! str_contains($request['caption'], 'DeleteMe'));
    }

    public function test_forbidden_caption_uses_one_companion_and_later_edits_update_it_without_losing_source_text(): void
    {
        $connection = TelegramConnection::factory()->connected()->create(['method' => 'bot']);
        $connection->account->update(['credentials' => '123:token']);
        $source = '<b>Original user caption</b>';
        $file = File::factory()->uploaded()->create(['user_id' => $connection->user_id, 'telegram_connection_id' => $connection->id, 'telegram_account_id' => $connection->telegram_account_id, 'telegram_message_id' => 90, 'metadata' => ['telegram_source_caption' => $source, 'telegram_import_marker' => 'preserved-marker']]);
        Queue::fake([TelegramOperationJob::class]);
        Http::fake([
            'api.telegram.org/*/editMessageCaption' => Http::response(['ok' => false, 'error_code' => 400, 'description' => "message can't be edited"], 400),
            'api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 101]]),
            'api.telegram.org/*/editMessageText' => Http::response(['ok' => true, 'result' => true]),
        ]);
        $this->actingAs($connection->user)->patchJson('/files/'.$file->id.'/description', ['description' => 'First description'])->assertOk();
        $first = FileOperation::query()->latest('id')->firstOrFail();
        (new TelegramOperationJob($first->id))->handle(app(TelegramOperationProcessor::class));
        $this->getJson('/file-operations')->assertJsonFragment(['operation' => 'metadata', 'status' => 'completed']);
        $this->patchJson('/files/'.$file->id.'/description', ['description' => 'Newest description'])->assertOk();
        $second = FileOperation::query()->latest('id')->firstOrFail();
        (new TelegramOperationJob($second->id))->handle(app(TelegramOperationProcessor::class));
        (new TelegramOperationJob($first->id))->handle(app(TelegramOperationProcessor::class));

        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/editMessageCaption') && str_contains($request['caption'], $source));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage') && $request['reply_parameters']['message_id'] === 90);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/editMessageText') && $request['message_id'] === 101 && str_contains($request['text'], 'Newest description'));
        $this->assertSame($source, $file->fresh()->metadata['telegram_source_caption']);
        $this->assertSame('preserved-marker', $file->fresh()->metadata['telegram_import_marker']);
    }

    public function test_expired_caption_edit_falls_back_to_a_companion_message(): void
    {
        $connection = TelegramConnection::factory()->connected()->create(['method' => 'bot']);
        $connection->account->update(['credentials' => '123:token']);
        $file = File::factory()->uploaded()->create([
            'user_id' => $connection->user_id,
            'telegram_connection_id' => $connection->id,
            'telegram_account_id' => $connection->telegram_account_id,
            'telegram_message_id' => 90,
            'metadata' => ['telegram_metadata_owned_caption' => true],
        ]);
        Queue::fake([TelegramOperationJob::class]);
        Http::fake([
            'api.telegram.org/*/editMessageCaption' => Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => 'MESSAGE_EDIT_TIME_EXPIRED',
            ], 400),
            'api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 101],
            ]),
        ]);

        $this->actingAs($connection->user)
            ->patchJson('/files/'.$file->id.'/description', ['description' => 'Updated after expiry'])
            ->assertOk();

        $operation = FileOperation::query()->latest('id')->firstOrFail();
        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && $request['reply_parameters']['message_id'] === 90
            && str_contains($request['text'], 'Updated after expiry'));
        $this->assertSame(101, $file->fresh()->metadata['telegram_metadata_companion_id']);
        $this->assertSame('completed', $operation->fresh()->status->value);
    }

    #[TestWith(['contents'])]
    #[TestWith(['contents_and_metadata'])]
    public function test_reordered_edits_project_only_the_latest_revision_allowed_by_scope(string $scope): void
    {
        $connection = TelegramConnection::factory()->connected()->create(['method' => 'bot', 'encryption_mode' => $scope]);
        $connection->account->update(['credentials' => '123:token']);
        $protected = $scope === 'contents_and_metadata';
        $firstMetadata = ['version' => 1, 'salt' => base64_encode(str_repeat('s', 32)), 'iv' => base64_encode(str_repeat('i', 12)), 'ciphertext' => base64_encode(str_repeat('c', 64))];
        $secondMetadata = array_replace($firstMetadata, ['ciphertext' => base64_encode(str_repeat('d', 64))]);
        $latestMetadata = array_replace($firstMetadata, ['ciphertext' => base64_encode(str_repeat('e', 64))]);
        $file = File::factory()->uploaded()->create([
            'user_id' => $connection->user_id,
            'telegram_connection_id' => $connection->id,
            'telegram_account_id' => $connection->telegram_account_id,
            'telegram_message_id' => 90,
            'name' => $protected ? 'opaque-reordered-id' : 'Initial.txt',
            'encryption_header' => 'authenticated-ciphertext-header',
            'metadata' => $protected
                ? ['protected_metadata' => $firstMetadata, 'telegram_metadata_owned_caption' => true]
                : ['telegram_metadata_owned_caption' => true],
        ]);
        Queue::fake([TelegramOperationJob::class]);
        $this->actingAs($connection->user);
        if ($protected) {
            $this->patchJson('/files/'.$file->id.'/protected-metadata', ['protected_metadata' => $secondMetadata, 'previous_metadata' => $firstMetadata])->assertOk();
            $this->patchJson('/files/'.$file->id.'/protected-metadata', ['protected_metadata' => $latestMetadata, 'previous_metadata' => $secondMetadata])->assertOk();
        } else {
            $this->patchJson('/files/'.$file->id.'/rename', ['name' => 'First.txt'])->assertOk();
            $this->patchJson('/files/'.$file->id.'/rename', ['name' => 'Newest.txt'])->assertOk();
        }
        $operations = FileOperation::query()->where('operation', 'metadata')->orderBy('id')->get();
        $this->assertCount(2, $operations);
        if ($protected) {
            $this->assertSame([], $operations->firstOrFail()->metadata);
        }
        Http::fake(['api.telegram.org/*/editMessageCaption' => Http::response(['ok' => true, 'result' => true])]);

        foreach ($operations->reverse() as $operation) {
            (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));
        }

        Http::assertSentCount(1);
        if ($protected) {
            Http::assertSent(fn ($request) => str_contains($request['caption'], 'opaque-reordered-id') && ! str_contains($request['caption'], 'Newest.txt') && ! str_contains($request['caption'], 'First.txt'));
        } else {
            Http::assertSent(fn ($request) => str_contains($request['caption'], 'Newest.txt') && ! str_contains($request['caption'], 'First.txt'));
        }
        $this->getJson('/file-operations')->assertOk()->assertJsonFragment(['operation' => 'metadata', 'status' => 'completed']);
        Queue::assertPushed(TelegramOperationJob::class, 2);
    }

    #[TestWith(['contents'])]
    #[TestWith(['contents_and_metadata'])]
    public function test_authenticated_metadata_edits_project_only_metadata_allowed_by_encryption_scope(string $scope): void
    {
        $connection = TelegramConnection::factory()->connected()->create([
            'method' => 'bot',
            'encryption_mode' => $scope,
        ]);
        $connection->account->update(['credentials' => '123:token']);
        $directory = Directory::factory()->create(['user_id' => $connection->user_id, 'name' => 'Readable folder']);
        $previous = ['version' => 1, 'salt' => base64_encode(str_repeat('s', 32)), 'iv' => base64_encode(str_repeat('i', 12)), 'ciphertext' => base64_encode(str_repeat('c', 64))];
        $next = array_replace($previous, ['ciphertext' => base64_encode(str_repeat('d', 64))]);
        $protected = $scope === 'contents_and_metadata';
        $file = File::factory()->uploaded()->create([
            'user_id' => $connection->user_id,
            'telegram_connection_id' => $connection->id,
            'telegram_account_id' => $connection->telegram_account_id,
            'directory_id' => $directory->id,
            'telegram_message_id' => 90,
            'name' => $protected ? 'opaque-storage-42' : 'secret-name.pdf',
            'original_name' => $protected ? 'opaque-storage-42' : 'secret-name.pdf',
            'extension' => $protected ? null : 'pdf',
            'mime_type' => $protected ? 'application/octet-stream' : 'application/pdf',
            'encryption_header' => 'authenticated-ciphertext-header',
            'description' => $protected ? null : 'secret-description',
            'metadata' => $protected
                ? ['protected_metadata' => $previous, 'telegram_metadata_owned_caption' => true]
                : ['plaintext_mime' => 'application/pdf', 'telegram_metadata_owned_caption' => true],
        ]);
        if (! $protected) {
            $file->tags()->attach(Tag::factory()->create([
                'user_id' => $connection->user_id,
                'name' => 'secret-tag',
            ])->id);
        }
        Queue::fake([TelegramOperationJob::class]);
        $caption = null;
        Http::fake(['api.telegram.org/*/editMessageCaption' => function ($request) use (&$caption) {
            $caption = $request['caption'];

            return Http::response(['ok' => true, 'result' => true]);
        }]);

        $this->actingAs($connection->user);
        if ($protected) {
            $this->patchJson('/files/'.$file->id.'/protected-metadata', [
                'protected_metadata' => $next,
                'previous_metadata' => $previous,
            ])->assertOk();
        } else {
            $this->patchJson('/files/'.$file->id.'/description', ['description' => 'updated description'])->assertOk();
        }
        $operation = FileOperation::query()->latest('id')->firstOrFail();
        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));

        $this->assertSame('completed', $operation->fresh()->status->value);
        $this->assertIsString($caption);
        $this->assertStringContainsString('Readable folder', $caption);
        $this->assertStringContainsString('/files?file='.$file->uuid, $caption);
        if ($protected) {
            $this->assertStringContainsString('opaque-storage-42', $caption);
            $this->assertStringNotContainsString('secret-name', $caption);
            $this->assertStringNotContainsString('application/pdf', $caption);
            $this->assertStringNotContainsString('secret-tag', $caption);
            $this->assertStringNotContainsString('secret-description', $caption);
        } else {
            $this->assertStringContainsString('secret-name.pdf', $caption);
            $this->assertStringContainsString('#secret_tag', $caption);
            $this->assertStringContainsString('updated description', $caption);
        }
    }

    public function test_protected_companion_recovery_reprojects_only_readable_path_and_opaque_identity(): void
    {
        $connection = TelegramConnection::factory()->connected()->create([
            'method' => 'bot',
            'encryption_mode' => 'contents_and_metadata',
        ]);
        $connection->account->update(['credentials' => '123:token']);
        $directory = Directory::factory()->create(['user_id' => $connection->user_id, 'name' => 'Readable folder']);
        $metadata = ['version' => 1, 'salt' => base64_encode(str_repeat('s', 32)), 'iv' => base64_encode(str_repeat('i', 12)), 'ciphertext' => base64_encode(str_repeat('c', 64))];
        $next = array_replace($metadata, ['ciphertext' => base64_encode(str_repeat('d', 64))]);
        $file = File::factory()->uploaded()->create([
            'user_id' => $connection->user_id,
            'telegram_connection_id' => $connection->id,
            'telegram_account_id' => $connection->telegram_account_id,
            'directory_id' => $directory->id,
            'telegram_message_id' => 90,
            'name' => 'opaque-companion-id',
            'original_name' => 'opaque-companion-id',
            'extension' => null,
            'mime_type' => 'application/octet-stream',
            'encryption_header' => 'authenticated-ciphertext-header',
            'metadata' => [
                'protected_metadata' => $metadata,
                'telegram_metadata_owned_caption' => true,
                'telegram_metadata_companion_id' => 101,
                'telegram_source_caption' => 'secret-source-caption',
            ],
        ]);
        Queue::fake([TelegramOperationJob::class]);
        Http::fake([
            'api.telegram.org/*/editMessageCaption' => Http::response(['ok' => false, 'error_code' => 400, 'description' => "message can't be edited"], 400),
            'api.telegram.org/*/editMessageText' => function ($request) {
                $this->assertSame(101, $request['message_id']);
                $this->assertStringContainsString('Readable folder', $request['text']);
                $this->assertStringContainsString('opaque-companion-id', $request['text']);
                $this->assertStringContainsString('/files?file=', $request['text']);
                foreach (['secret-source-caption', 'secret-name', 'secret-tag', 'secret-description', 'application/pdf', 'original-digest'] as $secret) {
                    $this->assertStringNotContainsString($secret, $request['text']);
                }

                return Http::response(['ok' => true, 'result' => true]);
            },
        ]);

        $this->actingAs($connection->user)->patchJson('/files/'.$file->id.'/protected-metadata', [
            'protected_metadata' => $next,
            'previous_metadata' => $metadata,
        ])->assertOk();
        $operation = FileOperation::query()->latest('id')->firstOrFail();
        (new TelegramOperationJob($operation->id))->handle(app(TelegramOperationProcessor::class));

        Http::assertSentCount(1);
        $this->assertSame(101, $file->fresh()->metadata['telegram_metadata_companion_id']);
        $this->assertSame('completed', $operation->fresh()->status->value);
    }
}
