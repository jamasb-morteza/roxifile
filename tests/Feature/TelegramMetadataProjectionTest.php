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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class TelegramMetadataProjectionTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['bot'])]
    #[TestWith(['invite'])]
    #[TestWith(['roxi'])]
    #[TestWith(['bot', 'contents'])]
    #[TestWith(['bot', 'contents_and_metadata'])]
    #[TestWith(['invite', 'contents'])]
    #[TestWith(['invite', 'contents_and_metadata'])]
    public function test_worker_projects_current_metadata_as_literal_text_with_hashtags_and_an_authenticated_link(string $method, string $mode = 'none'): void
    {
        $user = User::factory()->create();
        $connection = TelegramConnection::factory()->connected()->create(['user_id' => $user->id, 'method' => $method]);
        $connection->account->update(['credentials' => '123:token']);
        $parent = Directory::factory()->create(['user_id' => $user->id, 'name' => 'Work']);
        $directory = Directory::factory()->create(['user_id' => $user->id, 'name' => 'Invoices', 'parent_id' => $parent->id]);
        Queue::fake([TelegramOperationJob::class]);
        $response = $this->actingAs($user)->postJson('/files/upload', [
            'connection_id' => $connection->id, 'directory_id' => $directory->id,
            'file' => UploadedFile::fake()->createWithContent('report.txt', 'bytes'),
        ])->assertAccepted();
        $file = File::query()->findOrFail($response->json('file_id'));
        $file->update(['description' => '<b>literal</b> & [link](https://evil.test)']);
        $file->tags()->attach(Tag::factory()->create(['user_id' => $user->id, 'name' => 'Client work'])->id);
        $connection->update(['encryption_mode' => $mode]);
        $caption = null;
        if ($method === 'bot') {
            Http::fake(['api.telegram.org/*/sendDocument' => function ($request) use (&$caption) {
                $caption = $request->body();
                $this->assertStringNotContainsString('name="parse_mode"', $request->body());

                return Http::response(['ok' => true, 'result' => ['message_id' => 901, 'document' => ['file_id' => 'doc']]]);
            }]);
        } else {
            $runtime = $this->partialMock(RoxiRuntime::class);
            $runtime->shouldReceive('uploadFromCallable')->once()->andReturn(['_' => 'inputFile', 'id' => 1]);
            $runtime->shouldReceive('methodCallAsyncRead')->with('messages.sendMedia', Mockery::on(function (array $args) use (&$caption): bool {
                $caption = $args['message'];
                $this->assertSame([], $args['entities']);

                return true;
            }))->once()->andReturn(['_' => 'updateShortSentMessage', 'id' => 901]);
        }

        (new TelegramOperationJob($response->json('operation_id')))->handle(app(TelegramOperationProcessor::class));

        $operation = FileOperation::findOrFail($response->json('operation_id'));
        $this->assertSame('completed', $operation->status->value, (string) $operation->error);
        $this->assertSame('uploaded', $file->fresh()->status->value);
        $this->assertIsString($caption);
        if ($mode !== 'none') {
            $this->assertStringNotContainsString('Work/Invoices', $caption);
            $this->assertStringNotContainsString('Client_work', $caption);
            $this->assertStringNotContainsString('literal', $caption);
            $this->assertStringNotContainsString('/files?', $caption);

            return;
        }
        $this->assertStringContainsString('Work/Invoices/report.txt', $caption);
        $this->assertStringContainsString('#Client_work', $caption);
        $this->assertStringContainsString('<b>literal</b> & [link](https://evil.test)', $caption);
        $this->assertStringContainsString('/files?', $caption);
        $this->assertStringNotContainsString('download', $caption);
        $this->assertStringNotContainsString('token', $caption);
        $this->assertSame('completed', FileOperation::findOrFail($response->json('operation_id'))->status->value);
    }

    public function test_long_unicode_metadata_is_explicitly_shortened_without_changing_the_source(): void
    {
        $connection = TelegramConnection::factory()->connected()->create();
        $description = str_repeat('🗂️Important description ', 300);
        $file = File::factory()->create(['telegram_connection_id' => $connection->id, 'description' => $description]);

        $caption = app(TelegramMetadataProjection::class)->caption($file, 1024);

        $this->assertLessThanOrEqual(1024, intdiv(strlen(mb_convert_encoding($caption, 'UTF-16LE', 'UTF-8')), 2));
        $this->assertStringContainsString('[Shortened; full metadata in RoxiFile]', $caption);
        $this->assertStringEndsWith('/files?file='.$file->uuid, $caption);
        $this->assertSame($description, $file->fresh()->description);
    }

    public function test_path_uses_the_available_budget_before_tags_and_description(): void
    {
        $connection = TelegramConnection::factory()->connected()->create();
        $directory = Directory::factory()->create(['user_id' => $connection->user_id]);
        $directory->forceFill(['path' => rtrim(str_repeat('folder/', 60), '/')])->save();
        $file = File::factory()->create(['telegram_connection_id' => $connection->id, 'directory_id' => $directory->id, 'name' => 'report.txt', 'description' => null]);

        $caption = app(TelegramMetadataProjection::class)->caption($file, 1024);

        $this->assertStringContainsString(str_repeat('folder/', 60).'report.txt', $caption);
        $this->assertStringNotContainsString('[Shortened;', $caption);
    }

    #[TestWith(['contents'])]
    #[TestWith(['contents_and_metadata'])]
    #[TestWith(['future_encryption_scope'])]
    #[TestWith([null])]
    public function test_ordinary_projection_denies_every_mode_except_explicitly_unencrypted(?string $mode): void
    {
        $connection = TelegramConnection::factory()->connected()->create();
        $file = File::factory()->create(['telegram_connection_id' => $connection->id, 'description' => 'private description']);
        $connection->encryption_mode = $mode;
        $file->setRelation('connection', $connection);

        $this->assertSame('', app(TelegramMetadataProjection::class)->caption($file, 1024));
    }

    public function test_encrypted_file_header_denies_projection_even_if_connection_was_changed(): void
    {
        $connection = TelegramConnection::factory()->connected()->create();
        $file = File::factory()->create(['telegram_connection_id' => $connection->id, 'encryption_header' => 'encrypted-header']);

        $this->assertSame('', app(TelegramMetadataProjection::class)->caption($file, 1024));
    }

    public function test_metadata_protected_projection_contains_only_readable_path_opaque_identity_and_authenticated_link(): void
    {
        $connection = TelegramConnection::factory()->connected()->create(['encryption_mode' => 'contents_and_metadata']);
        $directory = Directory::factory()->create(['user_id' => $connection->user_id, 'name' => 'Readable folder']);
        $file = File::factory()->uploaded()->create([
            'user_id' => $connection->user_id,
            'telegram_connection_id' => $connection->id,
            'directory_id' => $directory->id,
            'name' => 'opaque-id-42',
            'original_name' => 'opaque-id-42',
            'extension' => null,
            'mime_type' => 'application/octet-stream',
            'encryption_header' => 'authenticated-header',
            'description' => null,
        ]);
        $file->tags()->attach(Tag::factory()->create(['user_id' => $connection->user_id, 'name' => 'never-send-this-tag'])->id);
        $file->forceFill(['metadata' => ['protected_metadata' => ['ciphertext' => 'never-send-this-envelope']]])->save();

        $caption = app(TelegramMetadataProjection::class)->caption($file->fresh(['connection', 'directory']), 1024);

        $this->assertStringContainsString('Readable folder', $caption);
        $this->assertStringContainsString('opaque-id-42', $caption);
        $this->assertStringContainsString('/files?file='.$file->uuid, $caption);
        $this->assertStringNotContainsString('never-send-this-tag', $caption);
        $this->assertStringNotContainsString('never-send-this-envelope', $caption);
        $this->assertStringNotContainsString('application/octet-stream', $caption);
        $this->assertStringNotContainsString('Path: Readable folder/opaque-id-42', $caption);
    }

    public function test_caption_rejection_surfaces_a_failed_transfer_and_retains_the_full_source_metadata(): void
    {
        $user = User::factory()->create();
        $connection = TelegramConnection::factory()->connected()->create(['user_id' => $user->id, 'method' => 'bot']);
        $connection->account->update(['credentials' => '123:token']);
        Queue::fake([TelegramOperationJob::class]);
        $response = $this->actingAs($user)->postJson('/files/upload', [
            'connection_id' => $connection->id,
            'file' => UploadedFile::fake()->createWithContent('report.txt', 'bytes'),
        ])->assertAccepted();
        $file = File::query()->findOrFail($response->json('file_id'));
        $file->update(['description' => 'Full description remains in RoxiFile']);
        Http::fake(['api.telegram.org/*/sendDocument' => Http::response(['ok' => false, 'description' => 'caption rejected'], 400)]);

        (new TelegramOperationJob($response->json('operation_id')))->handle(app(TelegramOperationProcessor::class));

        $operation = FileOperation::findOrFail($response->json('operation_id'));
        $this->assertSame('failed', $operation->status->value);
        $this->assertStringContainsString('caption rejected', $operation->error);
        $this->assertSame('failed', $file->fresh()->status->value);
        $this->assertSame('Full description remains in RoxiFile', $file->fresh()->description);
        $this->assertNull($file->fresh()->telegram_message_id);
    }
}
