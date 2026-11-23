<?php

namespace Tests\Feature\Telegram;

use App\Domains\Files\Actions\EnsureStorageCapacity;
use App\Domains\Files\Models\File;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Models\TelegramImportRequest;
use App\Domains\Telegram\Runtime\RoxiEventHandler;
use App\Domains\Telegram\Runtime\RoxiRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\ImportRuntimeFake;
use Tests\TestCase;

class OwnerImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_forward_is_prompted_once_and_root_confirmation_creates_an_independent_copy(): void
    {
        $connection = TelegramConnection::factory()->for(TelegramAccount::factory()->system(), 'account')->connected()->create(['chat_id' => -12345, 'target_user_id' => 42]);
        $runtime = new ImportRuntimeFake;
        $this->app->instance(RoxiRuntime::class, $runtime);
        $handler = (new \ReflectionClass(RoxiEventHandler::class))->newInstanceWithoutConstructor();
        $source = $this->source();
        $runtime->messages[10] = $source;

        $handler->onUpdateNewMessage(['message' => $source]);
        $handler->onUpdateNewMessage(['message' => $source]);

        $this->assertDatabaseCount('telegram_import_requests', 1);
        $this->assertDatabaseCount('files', 0);
        $request = TelegramImportRequest::query()->firstOrFail();
        $this->assertCount(1, $runtime->prompts);
        $handler->onUpdateNewMessage(['message' => $this->reply($request->prompt_message_id, 'root')]);
        $handler->onUpdateNewMessage(['message' => $this->reply($request->prompt_message_id, 'root')]);

        $file = File::query()->sole();
        $this->assertSame('report.pdf', $file->name);
        $this->assertSame(901, $file->telegram_message_id);
        $this->assertSame($connection->id, $file->telegram_connection_id);
        $this->assertSame('uploaded', $file->status->value);
        $this->assertCount(1, $runtime->copies);
        $this->assertSame('Original owner caption', $file->metadata['telegram_source_caption']);
        $this->assertStringContainsString('Original owner caption', $runtime->copies[0]['message']);
        $this->assertArrayHasKey(10, $runtime->messages);
    }

    public function test_foreign_anonymous_channel_and_self_authored_updates_cannot_prompt(): void
    {
        TelegramConnection::factory()->for(TelegramAccount::factory()->system(), 'account')->connected()->create(['chat_id' => -12345, 'target_user_id' => 42]);
        $runtime = new ImportRuntimeFake;
        $this->app->instance(RoxiRuntime::class, $runtime);
        $handler = (new \ReflectionClass(RoxiEventHandler::class))->newInstanceWithoutConstructor();
        foreach ([['from_id' => ['_' => 'peerUser', 'user_id' => 7]], ['from_id' => null],
            ['from_id' => ['_' => 'peerChannel', 'channel_id' => 42]], ['out' => true], ['post' => true],
            ['sender_chat' => ['id' => -12345]]] as $change) {
            $handler->onUpdateNewMessage(['message' => array_replace($this->source(), $change)]);
        }

        $this->assertDatabaseCount('telegram_import_requests', 0);
        $this->assertSame([], $runtime->prompts);
    }

    public function test_encrypted_destination_is_visibly_denied(): void
    {
        TelegramConnection::factory()->for(TelegramAccount::factory()->system(), 'account')->connected()->create(['chat_id' => -12345, 'target_user_id' => 42, 'encryption_mode' => 'contents']);
        $runtime = new ImportRuntimeFake;
        $this->app->instance(RoxiRuntime::class, $runtime);
        $handler = (new \ReflectionClass(RoxiEventHandler::class))->newInstanceWithoutConstructor();

        $handler->onUpdateNewMessage(['message' => $this->source()]);

        $this->assertDatabaseCount('telegram_import_requests', 0);
        $this->assertStringContainsString('encrypted', $runtime->prompts[0]['message']);
    }

    public function test_deleted_source_fails_visibly_without_creating_a_ready_file(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        unset($runtime->messages[10]);

        $handler->onUpdateNewMessage(['message' => $this->reply($request->prompt_message_id, 'root')]);

        $this->assertSame('failed', $request->refresh()->state);
        $this->assertDatabaseCount('files', 0);
        $this->assertSame([], $runtime->copies);
        $this->assertStringContainsString('Source unavailable', end($runtime->prompts)['message']);
    }

    public function test_cancel_and_expiry_preserve_source_and_restart_requires_fresh_confirmation(): void
    {
        $this->freezeTime();
        [$handler, $runtime, $request] = $this->pending();
        $this->travel(25)->hours();
        $handler->recoverTelegramImports();
        $this->assertSame('expired', $request->refresh()->state);
        $handler->onUpdateNewMessage(['message' => $this->reply($request->prompt_message_id, 'root')]);
        $this->assertDatabaseCount('files', 0);
        $handler->onUpdateNewMessage(['message' => $this->reply($request->prompt_message_id, 'restart')]);
        $this->assertSame('pending', $request->refresh()->state);
        $handler->onUpdateNewMessage(['message' => $this->reply($request->prompt_message_id, 'cancel')]);

        $this->assertSame('cancelled', $request->refresh()->state);
        $this->assertDatabaseCount('files', 0);
        $this->assertArrayHasKey(10, $runtime->messages);
        $this->assertSame([], $runtime->copies);
    }

    public function test_foreign_and_cross_chat_replies_do_not_confirm_another_import(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $foreign = $this->reply($request->prompt_message_id, 'root');
        $foreign['from_id']['user_id'] = 99;
        $crossChat = $this->reply($request->prompt_message_id, 'root');
        $crossChat['peer_id']['chat_id'] = 9876;

        $handler->onUpdateNewMessage(['message' => $foreign]);
        $handler->onUpdateNewMessage(['message' => $crossChat]);

        $this->assertSame('pending', $request->refresh()->state);
        $this->assertDatabaseCount('files', 0);
        $this->assertSame([], $runtime->copies);
    }

    public function test_concurrent_name_conflict_shows_a_suffix_and_requires_reconfirmation(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        File::factory()->create(['user_id' => $request->user_id, 'directory_id' => null, 'name' => 'report.pdf']);

        $handler->onUpdateNewMessage(['message' => $this->reply($request->prompt_message_id, 'root')]);

        $this->assertSame('report (1).pdf', $request->refresh()->name);
        $this->assertSame('pending', $request->state);
        $this->assertSame([], $runtime->copies);
        $this->assertStringContainsString('report (1).pdf', end($runtime->prompts)['message']);
        $handler->onUpdateNewMessage(['message' => $this->reply($request->prompt_message_id, 'root')]);
        $this->assertDatabaseHas('files', ['name' => 'report (1).pdf', 'telegram_message_id' => 901]);
    }

    public function test_quota_is_rechecked_at_confirmation(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        config(['telegram.storage.max_user_bytes' => 99]);

        $handler->onUpdateNewMessage(['message' => $this->reply($request->prompt_message_id, 'root')]);

        $this->assertSame('failed', $request->refresh()->state);
        $this->assertStringContainsString('storage limit', end($runtime->prompts)['message']);
        $this->assertDatabaseCount('files', 0);
        $this->assertSame([], $runtime->copies);
    }

    public function test_restart_reconciles_a_lost_send_response_without_resending_or_expiring_confirmed_work(): void
    {
        $this->freezeTime();
        [$handler, $runtime, $request] = $this->pending();
        $runtime->loseSendResponse = true;
        $handler->onUpdateNewMessage(['message' => $this->reply($request->prompt_message_id, 'root')]);
        $this->assertSame('uncertain', $request->refresh()->state);
        $this->assertDatabaseCount('files', 0);
        $this->travel(25)->hours();
        $restarted = (new \ReflectionClass(RoxiEventHandler::class))->newInstanceWithoutConstructor();
        $restarted->recoverTelegramImports();

        $this->assertSame('completed', $request->refresh()->state);
        $this->assertDatabaseHas('files', ['telegram_message_id' => 901, 'name' => 'report.pdf']);
        $this->assertCount(1, $runtime->copies);
    }

    public function test_unresolved_send_is_retained_outside_ready_files_and_never_blindly_resent(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $runtime->loseSendResponse = true;
        $runtime->hideSearchResult = true;
        $handler->onUpdateNewMessage(['message' => $this->reply($request->prompt_message_id, 'root')]);
        $handler->recoverTelegramImports();
        $handler->onUpdateNewMessage(['message' => $this->reply($request->prompt_message_id, 'retry')]);

        $this->assertSame('uncertain', $request->refresh()->state);
        $this->assertDatabaseCount('files', 0);
        $this->assertCount(1, $runtime->copies);
    }

    public function test_photo_messages_are_copied_as_photos_and_each_album_item_has_its_own_request(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $photo = array_replace($this->source(), ['id' => 11, 'grouped_id' => 345, 'media' => [
            '_' => 'messageMediaPhoto', 'photo' => ['_' => 'photo', 'id' => 78, 'access_hash' => 89, 'file_reference' => 'ref',
                'sizes' => [['_' => 'photoSize', 'size' => 200]]]]]);
        $runtime->messages[11] = $photo;
        $handler->onUpdateNewMessage(['message' => $photo]);
        $photoRequest = TelegramImportRequest::query()->where('source_message_id', 11)->sole();
        $handler->onUpdateNewMessage(['message' => $this->reply($photoRequest->prompt_message_id, 'root')]);

        $this->assertDatabaseCount('telegram_import_requests', 2);
        $this->assertSame('pending', $request->refresh()->state);
        $this->assertDatabaseHas('files', ['name' => 'telegram-photo-78.jpg', 'mime_type' => 'image/jpeg', 'size' => 200]);
        $this->assertSame('inputMediaPhoto', $runtime->copies[0]['media']['_']);
    }

    public function test_in_flight_import_reserves_quota_for_other_transfers(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $request->update(['state' => 'uncertain']);
        config(['telegram.storage.max_user_bytes' => 150]);
        $source = array_replace($this->source(), ['id' => 11]);
        $runtime->messages[11] = $source;
        $handler->onUpdateNewMessage(['message' => $source]);
        $second = TelegramImportRequest::query()->where('source_message_id', 11)->sole();
        $handler->onUpdateNewMessage(['message' => $this->reply($second->prompt_message_id, 'root')]);

        $this->assertSame('failed', $second->refresh()->state);
        $this->assertSame([], $runtime->copies);
    }

    public function test_conflict_created_during_copy_reconfirms_name_without_sending_again(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $runtime->duringCopy = function () use ($request): void {
            File::factory()->create(['user_id' => $request->user_id, 'directory_id' => null, 'name' => 'report.pdf']);
        };
        $handler->onUpdateNewMessage(['message' => $this->reply($request->prompt_message_id, 'root')]);

        $this->assertSame('conflict', $request->refresh()->state);
        $this->assertSame('report (1).pdf', $request->name);
        $this->assertDatabaseCount('files', 1);
        $handler->onUpdateNewMessage(['message' => $this->reply($request->prompt_message_id, 'root')]);
        $this->assertDatabaseHas('files', ['name' => 'report (1).pdf', 'telegram_message_id' => 901]);
        $this->assertCount(1, $runtime->copies);
    }

    public function test_lost_success_notification_cannot_duplicate_the_registered_file(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $runtime->failSuccessNotification = true;
        $handler->onUpdateNewMessage(['message' => $this->reply($request->prompt_message_id, 'root')]);
        $handler->recoverTelegramImports();
        $handler->onUpdateNewMessage(['message' => $this->reply($request->prompt_message_id, 'retry')]);

        $this->assertSame('completed', $request->refresh()->state);
        $this->assertDatabaseCount('files', 1);
        $this->assertCount(1, $runtime->copies);
    }

    public function test_failed_unanswered_prompt_expires_without_discarding_its_source(): void
    {
        $this->freezeTime();
        [$handler, $runtime, $request] = $this->pending();
        $request->update(['state' => 'failed']);
        $this->travel(25)->hours();

        $handler->recoverTelegramImports();

        $this->assertSame('expired', $request->refresh()->state);
        $this->assertArrayHasKey(10, $runtime->messages);
        $this->assertDatabaseCount('files', 0);
    }

    public function test_cancel_after_copy_conflict_removes_only_managed_copy_and_releases_reservation(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $runtime->duringCopy = function () use ($request): void {
            File::factory()->create(['user_id' => $request->user_id, 'directory_id' => null, 'name' => 'report.pdf', 'size' => 0]);
        };
        $handler->onUpdateNewMessage(['message' => $this->reply($request->prompt_message_id, 'root')]);
        $request->refresh();

        $handler->onUpdateNewMessage(['message' => $this->reply($request->prompt_message_id, 'cancel')]);

        $this->assertSame('cancelled', $request->refresh()->state);
        $this->assertSame([['chat' => -12345, 'ids' => [901]]], $runtime->deletions);
        $this->assertArrayHasKey(10, $runtime->messages);
        $this->assertDatabaseCount('files', 1);
        config(['telegram.storage.max_user_bytes' => 100]);
        app(EnsureStorageCapacity::class)->handle(TelegramConnection::query()->findOrFail($request->telegram_connection_id)->user, 100);
    }

    public function test_uncertain_cancel_cleanup_retries_idempotently_after_restart(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $runtime->duringCopy = function () use ($request): void {
            File::factory()->create(['user_id' => $request->user_id, 'directory_id' => null, 'name' => 'report.pdf']);
        };
        $handler->onUpdateNewMessage(['message' => $this->reply($request->prompt_message_id, 'root')]);
        $runtime->loseDeleteResponse = true;
        $handler->onUpdateNewMessage(['message' => $this->reply($request->refresh()->prompt_message_id, 'cancel')]);
        $this->assertSame('cancelling', $request->refresh()->state);
        $runtime->loseDeleteResponse = false;

        $handler->recoverTelegramImports();

        $this->assertSame('cancelled', $request->refresh()->state);
        $this->assertDatabaseCount('files', 1);
        $this->assertSame([['chat' => -12345, 'ids' => [901]], ['chat' => -12345, 'ids' => [901]]], $runtime->deletions);
        $this->assertArrayHasKey(10, $runtime->messages);
    }

    #[TestWith([['status' => 'disabled']])]
    #[TestWith([['chat_id' => -99887]])]
    public function test_recovery_of_uncertain_import_revalidates_connection_before_remote_lookup(array $change): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $runtime->loseSendResponse = true;
        $handler->onUpdateNewMessage(['message' => $this->reply($request->prompt_message_id, 'root')]);
        TelegramConnection::query()->whereKey($request->telegram_connection_id)->update($change);

        $handler->recoverTelegramImports();

        $this->assertSame([], $runtime->searches);
        $this->assertSame('uncertain', $request->refresh()->state);
        $this->assertDatabaseCount('files', 0);
    }

    public function test_registration_revalidates_connection_after_remote_reconciliation(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $runtime->loseSendResponse = true;
        $handler->onUpdateNewMessage(['message' => $this->reply($request->prompt_message_id, 'root')]);
        $runtime->duringSearch = function () use ($request): void {
            TelegramConnection::query()->whereKey($request->telegram_connection_id)->update(['chat_id' => -99887]);
        };

        $handler->recoverTelegramImports();

        $this->assertSame('uncertain', $request->refresh()->state);
        $this->assertSame(901, $request->managed_message_id);
        $this->assertDatabaseCount('files', 0);
    }

    private function pending(): array
    {
        TelegramConnection::factory()->for(TelegramAccount::factory()->system(), 'account')->connected()->create(['chat_id' => -12345, 'target_user_id' => 42]);
        $runtime = new ImportRuntimeFake;
        $runtime->messages[10] = $this->source();
        $this->app->instance(RoxiRuntime::class, $runtime);
        $handler = (new \ReflectionClass(RoxiEventHandler::class))->newInstanceWithoutConstructor();
        $handler->onUpdateNewMessage(['message' => $this->source()]);

        return [$handler, $runtime, TelegramImportRequest::query()->sole()];
    }

    private function source(): array
    {
        return ['_' => 'message', 'id' => 10, 'peer_id' => ['_' => 'peerChat', 'chat_id' => 12345],
            'from_id' => ['_' => 'peerUser', 'user_id' => 42], 'fwd_from' => ['from_id' => ['user_id' => 999]],
            'message' => 'Original owner caption', 'media' => ['_' => 'messageMediaDocument', 'document' => ['_' => 'document', 'id' => 77,
                'access_hash' => 88, 'file_reference' => 'ref', 'size' => 100, 'mime_type' => 'application/pdf',
                'attributes' => [['_' => 'documentAttributeFilename', 'file_name' => 'report.pdf']]]]];
    }

    private function reply(int $promptId, string $text): array
    {
        return ['_' => 'message', 'id' => 20, 'peer_id' => ['_' => 'peerChat', 'chat_id' => 12345],
            'from_id' => ['_' => 'peerUser', 'user_id' => 42], 'message' => $text,
            'reply_to' => ['reply_to_msg_id' => $promptId]];
    }
}
