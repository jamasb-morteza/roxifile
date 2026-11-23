<?php

namespace Tests\Feature\Telegram;

use App\Domains\Files\Actions\RenameDirectory;
use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\Tag;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Models\TelegramImportRequest;
use App\Domains\Telegram\Runtime\RoxiEventHandler;
use App\Domains\Telegram\Runtime\RoxiRuntime;
use App\Domains\Telegram\Services\TelegramMetadataProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ImportRuntimeFake;
use Tests\TestCase;

class ImportDetailsTest extends TestCase
{
    use RefreshDatabase;

    private int $replyId = 20;

    public function test_owner_selects_existing_folder_tags_and_description_then_confirms_preview(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $folder = Directory::factory()->create(['user_id' => $request->user_id, 'name' => 'Trips', 'path' => 'Trips']);
        $tag = Tag::factory()->create(['user_id' => $request->user_id, 'name' => 'Summer']);
        $this->reply($handler, $request, 'details');
        $this->reply($handler, $request, 'folder /Trips');
        $this->reply($handler, $request, 'tags Summer, Family');
        $this->reply($handler, $request, 'description Keep Original Case');
        $this->assertDatabaseCount('files', 0);
        $this->assertStringContainsString('/Trips/report.pdf', end($runtime->prompts)['message']);
        $this->assertStringContainsString('Keep Original Case', end($runtime->prompts)['message']);

        $this->reply($handler, $request, 'confirm');

        $this->assertStringContainsString('/Trips/report.pdf', end($runtime->prompts)['message']);
        $file = File::query()->sole();
        $this->assertSame($folder->id, $file->directory_id);
        $this->assertSame('Keep Original Case', $file->description);
        $this->assertSame(['Family', 'Summer'], $file->tags()->orderBy('name')->pluck('name')->all());
        $this->assertTrue($file->tags()->whereKey($tag->id)->exists());
        $caption = app(TelegramMetadataProjection::class)->caption($file, 1024);
        $this->assertStringContainsString('Trips/report.pdf', $caption);
        $this->assertStringContainsString('Keep Original Case', $caption);
        $this->assertCount(1, $runtime->copies);
    }

    public function test_new_path_requires_explicit_creation_and_duplicate_create_does_not_repeat_work(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $this->reply($handler, $request, 'details');
        $this->reply($handler, $request, 'folder /New/Invoices');
        $this->assertDatabaseCount('directories', 0);
        $this->assertStringContainsString('create', end($runtime->prompts)['message']);
        $create = $this->reply($handler, $request, 'create');
        $handler->onUpdateNewMessage(['message' => $create]);
        $this->assertDatabaseCount('directories', 2);
        $this->assertDatabaseCount('files', 0);
        $this->reply($handler, $request, 'tags -');
        $this->reply($handler, $request, 'description -');
        $this->reply($handler, $request, 'confirm');

        $folder = Directory::query()->where('user_id', $request->user_id)->where('path', 'New/Invoices')->sole();
        $this->assertDatabaseHas('files', ['name' => 'report.pdf', 'directory_id' => $folder->id]);
        $this->assertCount(1, $runtime->copies);
    }

    public function test_invalid_input_is_correctable_and_cancellation_does_not_create_proposed_folders_or_tags(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $this->reply($handler, $request, 'details');
        $this->reply($handler, $request, 'folder ../Bad');
        $this->assertStringContainsString('valid path', end($runtime->prompts)['message']);
        $this->reply($handler, $request, 'folder /Pending');
        $this->reply($handler, $request, 'cancel');

        $this->assertSame('cancelled', $request->state);
        $this->assertDatabaseCount('directories', 0);
        $this->assertDatabaseCount('tags', 0);
        $this->assertSame([], $runtime->copies);
        $this->assertArrayHasKey(10, $runtime->messages);
    }

    public function test_deleted_folder_never_falls_back_to_root_and_details_can_be_corrected(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $folder = Directory::factory()->create(['user_id' => $request->user_id, 'name' => 'Trips', 'path' => 'Trips']);
        $this->preview($handler, $request, '/Trips');
        $folder->delete();

        $this->reply($handler, $request, 'confirm');

        $this->assertDatabaseCount('files', 0);
        $this->assertSame([], $runtime->copies);
        $this->assertStringContainsString('unavailable', end($runtime->prompts)['message']);
        $this->assertSame('Family', $request->details['tags'][0]);
        $this->reply($handler, $request, 'folder /');
        $this->reply($handler, $request, 'tags Family');
        $this->reply($handler, $request, 'description Fixed');
        $this->reply($handler, $request, 'confirm');
        $this->assertDatabaseHas('files', ['directory_id' => null, 'description' => 'Fixed']);
    }

    public function test_folder_deleted_during_send_keeps_managed_copy_and_requires_a_new_destination_without_resend(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $folder = Directory::factory()->create(['user_id' => $request->user_id, 'name' => 'Trips', 'path' => 'Trips']);
        $this->preview($handler, $request, '/Trips');
        $runtime->duringCopy = fn () => $folder->delete();
        $this->reply($handler, $request, 'confirm');
        $this->assertSame('conflict', $request->state);
        $this->assertDatabaseCount('files', 0);
        $this->reply($handler, $request, 'folder /');
        $this->reply($handler, $request, 'tags Family');
        $this->reply($handler, $request, 'description Fixed');
        $this->reply($handler, $request, 'confirm');

        $this->assertDatabaseHas('files', ['directory_id' => null, 'description' => 'Fixed', 'telegram_message_id' => 901]);
        $this->assertCount(1, $runtime->copies);
    }

    public function test_colliding_name_in_selected_folder_reconfirms_suffix_without_changing_root(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $folder = Directory::factory()->create(['user_id' => $request->user_id, 'name' => 'Trips', 'path' => 'Trips']);
        File::factory()->create(['user_id' => $request->user_id, 'directory_id' => $folder->id, 'name' => 'report.pdf']);
        $this->preview($handler, $request, '/Trips');
        $this->reply($handler, $request, 'confirm');
        $this->assertSame([], $runtime->copies);
        $this->assertStringContainsString('/Trips/report (1).pdf', end($runtime->prompts)['message']);
        $this->reply($handler, $request, 'confirm');

        $this->assertDatabaseHas('files', ['directory_id' => $folder->id, 'name' => 'report (1).pdf', 'telegram_message_id' => 901]);
    }

    public function test_expiry_and_restart_preserve_sources_and_require_new_confirmation(): void
    {
        $this->freezeTime();
        [$handler, $runtime, $request] = $this->pending();
        $this->preview($handler, $request, '/');
        $this->travel(25)->hours();
        $handler->recoverTelegramImports();
        $this->assertSame('expired', $request->refresh()->state);
        $this->reply($handler, $request, 'confirm');
        $this->assertDatabaseCount('files', 0);
        $this->reply($handler, $request, 'restart');
        $this->assertSame('pending', $request->state);
        $this->assertArrayHasKey(10, $runtime->messages);
        $this->assertSame([], $runtime->copies);
    }

    public function test_wrong_owner_cross_chat_and_late_prompt_replies_cannot_advance_details(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $details = $this->reply($handler, $request, 'details');
        $folderReply = $this->reply($handler, $request, 'folder /');
        $wrongOwner = $folderReply;
        $wrongOwner['reply_to']['reply_to_msg_id'] = $request->prompt_message_id;
        $wrongOwner['from_id']['user_id'] = 999;
        $crossChat = $wrongOwner;
        $crossChat['from_id']['user_id'] = 42;
        $crossChat['peer_id']['chat_id'] = 9876;

        $handler->onUpdateNewMessage(['message' => $wrongOwner]);
        $handler->onUpdateNewMessage(['message' => $crossChat]);
        $handler->onUpdateNewMessage(['message' => $details]);
        $handler->onUpdateNewMessage(['message' => $folderReply]);

        $this->assertSame('tags', $request->refresh()->details['stage']);
        $this->assertDatabaseCount('files', 0);
        $this->assertSame([], $runtime->copies);
    }

    public function test_independent_album_conversations_survive_worker_restart_and_duplicate_confirmation(): void
    {
        [$handler, $runtime, $first] = $this->pending();
        $source = $runtime->messages[10];
        $source['id'] = 11;
        $source['grouped_id'] = 222;
        $runtime->messages[11] = $source;
        $handler->onUpdateNewMessage(['message' => $source]);
        $second = TelegramImportRequest::query()->where('source_message_id', 11)->sole();
        $this->reply($handler, $first, 'details');
        $this->preview($handler, $second, '/');
        $handler = (new \ReflectionClass(RoxiEventHandler::class))->newInstanceWithoutConstructor();
        $confirm = $this->reply($handler, $second, 'confirm');
        $handler->onUpdateNewMessage(['message' => $confirm]);

        $this->assertSame('details', $first->refresh()->state);
        $this->assertSame('folder', $first->details['stage']);
        $this->assertSame('completed', $second->refresh()->state);
        $this->assertDatabaseCount('files', 1);
        $this->assertCount(1, $runtime->copies);
    }

    public function test_tag_bounds_are_correctable_and_foreign_tags_are_not_attached(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $foreign = Tag::factory()->create(['name' => 'Family']);
        $this->reply($handler, $request, 'details');
        $this->reply($handler, $request, 'folder /');
        $this->reply($handler, $request, 'tags '.str_repeat('x', 33));
        $this->assertStringContainsString('32 characters', end($runtime->prompts)['message']);
        $this->reply($handler, $request, 'tags Family');
        $this->reply($handler, $request, 'description Corrected');
        $this->reply($handler, $request, 'confirm');

        $file = File::query()->sole();
        $this->assertFalse($file->tags()->whereKey($foreign->id)->exists());
        $this->assertSame($request->user_id, $file->tags()->sole()->user_id);
    }

    public function test_long_description_preview_preserves_all_text_across_telegram_sized_messages(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $this->reply($handler, $request, 'details');
        $this->reply($handler, $request, 'folder /');
        $this->reply($handler, $request, 'tags -');
        $beforePreview = count($runtime->prompts);
        $description = str_repeat('😀', 5000);

        $this->reply($handler, $request, 'description '.$description);

        $parts = array_slice($runtime->prompts, $beforePreview);
        $this->assertGreaterThan(1, count($parts));
        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(4096, intdiv(strlen(mb_convert_encoding($part['message'], 'UTF-16LE', 'UTF-8')), 2));
        }
        $this->assertSame($description, $request->details['description']);
        $this->reply($handler, $request, 'confirm');
        $this->assertSame($description, File::query()->sole()->description);
    }

    public function test_lost_details_prompt_is_recovered_after_restart_without_importing_early(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $this->reply($handler, $request, 'details');
        $this->reply($handler, $request, 'folder /');
        $this->reply($handler, $request, 'tags Family');
        $runtime->failNextPrompt = true;
        $this->reply($handler, $request, 'description Recovered');
        $this->assertNull($request->prompt_message_id);
        $this->assertDatabaseCount('files', 0);
        $handler = (new \ReflectionClass(RoxiEventHandler::class))->newInstanceWithoutConstructor();

        $handler->recoverTelegramImports();

        $this->assertNotNull($request->refresh()->prompt_message_id);
        $this->assertStringContainsString('Recovered', end($runtime->prompts)['message']);
        $this->reply($handler, $request, 'confirm');
        $this->assertDatabaseHas('files', ['description' => 'Recovered']);
        $this->assertCount(1, $runtime->copies);
    }

    public function test_folder_renamed_during_send_waits_for_owner_reconfirmation_across_recovery(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $folder = Directory::factory()->create(['user_id' => $request->user_id, 'name' => 'Trips', 'path' => 'Trips']);
        $this->preview($handler, $request, '/Trips');
        $runtime->duringCopy = fn () => app(RenameDirectory::class)->handle($folder, 'Renamed');
        $this->reply($handler, $request, 'confirm');
        $this->assertStringContainsString('/Renamed/report.pdf', end($runtime->prompts)['message']);
        $this->assertDatabaseCount('files', 0);
        $handler = (new \ReflectionClass(RoxiEventHandler::class))->newInstanceWithoutConstructor();

        $handler->recoverTelegramImports();

        $this->assertDatabaseCount('files', 0);
        $this->assertSame('conflict', $request->refresh()->state);
        $this->assertSame(901, $request->managed_message_id);
        $this->reply($handler, $request, 'confirm');
        $this->assertDatabaseHas('files', ['directory_id' => $folder->id, 'telegram_message_id' => 901]);
        $this->assertCount(1, $runtime->copies);
    }

    public function test_description_over_limit_is_rejected_without_losing_details_and_can_be_corrected(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $this->preview($handler, $request, '/');

        $this->reply($handler, $request, 'description '.str_repeat('x', 5001));

        $this->assertStringContainsString('Description is limited to 5000 characters.', end($runtime->prompts)['message']);
        $this->assertSame('Memories', $request->details['description']);
        $this->assertSame(['Family'], $request->details['tags']);
        $this->assertDatabaseCount('files', 0);
        $this->assertSame([], $runtime->copies);
        $this->reply($handler, $request, 'description Corrected');
        $this->reply($handler, $request, 'confirm');
        $this->assertSame('Corrected', File::query()->sole()->description);
        $this->assertCount(1, $runtime->copies);
    }

    public function test_more_than_twenty_tags_is_rejected_and_twenty_tags_can_be_confirmed(): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $this->preview($handler, $request, '/');
        $tags = array_map(fn (int $number): string => 'Tag'.$number, range(1, 21));

        $this->reply($handler, $request, 'tags '.implode(', ', $tags));

        $this->assertStringContainsString('Use up to 20 non-empty tags', end($runtime->prompts)['message']);
        $this->assertSame(['Family'], $request->details['tags']);
        $this->assertSame('Memories', $request->details['description']);
        $this->assertDatabaseCount('tags', 0);
        $this->assertDatabaseCount('files', 0);
        $this->assertSame([], $runtime->copies);
        $accepted = array_slice($tags, 0, 20);
        $this->reply($handler, $request, 'tags '.implode(', ', $accepted));
        $this->reply($handler, $request, 'description Corrected');
        $this->reply($handler, $request, 'confirm');
        $this->assertSame($accepted, File::query()->sole()->tags()->orderBy('tags.id')->pluck('name')->all());
        $this->assertCount(1, $runtime->copies);
    }

    #[DataProvider('invalidFolderPaths')]
    public function test_invalid_folder_path_is_visibly_rejected_and_can_be_corrected(string $path): void
    {
        [$handler, $runtime, $request] = $this->pending();
        $this->reply($handler, $request, 'details');

        $this->reply($handler, $request, 'folder '.$path);

        $this->assertStringContainsString('Use a valid path', end($runtime->prompts)['message']);
        $this->assertSame('folder', $request->details['stage']);
        $this->assertDatabaseCount('directories', 0);
        $this->assertDatabaseCount('files', 0);
        $this->assertSame([], $runtime->copies);
        $this->assertArrayHasKey(10, $runtime->messages);
        $this->reply($handler, $request, 'folder /');
        $this->reply($handler, $request, 'tags -');
        $this->reply($handler, $request, 'description -');
        $this->reply($handler, $request, 'confirm');
        $this->assertDatabaseHas('files', ['directory_id' => null, 'name' => 'report.pdf']);
        $this->assertCount(1, $runtime->copies);
    }

    public static function invalidFolderPaths(): array
    {
        return [
            'total path exceeds 2048 characters' => [implode('/', array_fill(0, 17, str_repeat('a', 120)))],
            'depth exceeds twenty segments' => [implode('/', array_fill(0, 21, 'Folder'))],
            'folder name exceeds 128 characters' => [str_repeat('a', 129)],
            'segment has leading whitespace' => ['/Trips/ Day'],
            'empty segment' => ['/Trips//Day'],
            'current directory segment' => ['/Trips/./Day'],
            'backslash in segment' => ['/Trips\\Day'],
            'control character in segment' => ["/Trips/\x01Day"],
        ];
    }

    private function preview(RoxiEventHandler $handler, TelegramImportRequest $request, string $folder): void
    {
        $this->reply($handler, $request, 'details');
        $this->reply($handler, $request, 'folder '.$folder);
        $this->reply($handler, $request, 'tags Family');
        $this->reply($handler, $request, 'description Memories');
    }

    private function pending(): array
    {
        $connection = TelegramConnection::factory()->for(TelegramAccount::factory()->system(), 'account')->connected()
            ->create(['chat_id' => -12345, 'target_user_id' => 42]);
        $source = ['_' => 'message', 'id' => 10, 'peer_id' => ['_' => 'peerChat', 'chat_id' => 12345],
            'from_id' => ['_' => 'peerUser', 'user_id' => 42], 'message' => 'Owner caption',
            'media' => ['document' => ['_' => 'document', 'id' => 77, 'access_hash' => 88, 'file_reference' => 'ref',
                'size' => 100, 'mime_type' => 'application/pdf', 'attributes' => [['_' => 'documentAttributeFilename', 'file_name' => 'report.pdf']]]]];
        $runtime = new ImportRuntimeFake;
        $runtime->messages[10] = $source;
        $this->app->instance(RoxiRuntime::class, $runtime);
        $handler = (new \ReflectionClass(RoxiEventHandler::class))->newInstanceWithoutConstructor();
        $handler->onUpdateNewMessage(['message' => $source]);

        return [$handler, $runtime, TelegramImportRequest::query()->sole()];
    }

    private function reply(RoxiEventHandler $handler, TelegramImportRequest $request, string $text): array
    {
        $message = ['_' => 'message', 'id' => $this->replyId++, 'peer_id' => ['_' => 'peerChat', 'chat_id' => 12345],
            'from_id' => ['_' => 'peerUser', 'user_id' => 42], 'message' => $text,
            'reply_to' => ['reply_to_msg_id' => $request->refresh()->prompt_message_id]];
        $handler->onUpdateNewMessage(['message' => $message]);
        $request->refresh();

        return $message;
    }
}
