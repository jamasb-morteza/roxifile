<?php

namespace Tests\Feature;

use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Contracts\TelegramStorageDriver;
use App\Domains\Telegram\Drivers\FakeTelegramStorageDriver;
use App\Domains\Telegram\Jobs\TelegramOperationJob;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\RoxiRuntime;
use App\Domains\Telegram\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EncryptedConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_configure_only_wrapped_keys_on_an_empty_connection(): void
    {
        config(['telegram.contents_encryption_enabled' => true]);
        $user = User::factory()->create();
        $connection = TelegramConnection::factory()->connected()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson('/telegram/connections/'.$connection->id.'/encryption', ['envelope' => $this->envelope()])
            ->assertOk()->assertJsonPath('encryption_mode', 'contents');

        $this->assertDatabaseHas('telegram_connections', ['id' => $connection->id, 'encryption_mode' => 'contents']);
        $this->assertSame($this->envelope(), $connection->refresh()->vault_envelope);
    }

    public function test_ciphertext_survives_queued_upload_and_prepared_download_without_plaintext(): void
    {
        $user = User::factory()->create();
        $connection = TelegramConnection::factory()->connected()->create(['user_id' => $user->id, 'encryption_mode' => 'contents', 'vault_envelope' => $this->envelope()]);
        $driver = new FakeTelegramStorageDriver;
        $this->app->instance(StorageManager::class, new class($driver) extends StorageManager
        {
            public function __construct(private FakeTelegramStorageDriver $driver) {}

            public function for(TelegramConnection $connection): TelegramStorageDriver
            {
                return $this->driver;
            }
        });
        $plaintext = 'This content must stay off the server.';
        $ciphertext = $this->ciphertext($user, $connection, $plaintext);

        $response = $this->actingAs($user)->postJson('/files/upload', [
            'connection_id' => $connection->id, 'encrypted' => true, 'plaintext_mime' => 'text/plain',
            'file' => UploadedFile::fake()->createWithContent('secret.txt', $ciphertext),
        ])->assertAccepted();

        $file = File::query()->findOrFail($response->json('file_id'));
        $this->assertSame('uploaded', $file->status->value);
        $this->assertSame(strlen($ciphertext), $file->size);
        $this->assertSame('application/octet-stream', $file->mime_type);
        $this->assertSame($ciphertext, $driver->contents[$file->telegram_message_id]);
        $this->assertStringNotContainsString($plaintext, json_encode(FileOperation::query()->firstOrFail()->metadata));
        $this->assertNotNull($connection->refresh()->encryption_locked_at);

        $operation = $this->postJson('/files/'.$file->id.'/prepare-download')->assertAccepted()->json('operation_id');
        $download = $this->get('/files/'.$file->id.'/download?operation_id='.$operation.'&inline=1')->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertStringStartsWith('attachment;', $download->headers->get('Content-Disposition'));
        $this->assertSame($ciphertext, file_get_contents($download->baseResponse->getFile()->getPathname()));
        foreach (Storage::disk('local')->allFiles() as $path) {
            $this->assertStringNotContainsString($plaintext, Storage::disk('local')->get($path));
        }
    }

    public function test_setup_is_opt_in_and_rejects_foreign_users_and_unwrapped_keys(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        $connection = TelegramConnection::factory()->connected()->create(['user_id' => $owner->id]);
        $url = '/telegram/connections/'.$connection->id.'/encryption';

        $this->postJson($url, ['envelope' => $this->envelope()])->assertUnauthorized();
        $this->actingAs($foreign)->postJson($url, ['envelope' => $this->envelope()])->assertForbidden();
        $this->actingAs($owner)->postJson($url, ['envelope' => $this->envelope()])->assertUnprocessable();
        config(['telegram.contents_encryption_enabled' => true]);
        $invalid = $this->envelope();
        $invalid['raw_key'] = 'must never be stored';
        $this->postJson($url, ['envelope' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('envelope');
        $invalid = $this->envelope();
        $invalid['password']['ciphertext'] = 'plaintext';
        $this->postJson($url, ['envelope' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('envelope.password.ciphertext');
        $this->assertSame('none', $connection->refresh()->encryption_mode);
        $this->assertNull($connection->vault_envelope);
    }

    public function test_first_reservation_locks_mode_even_after_cancel_and_file_removal(): void
    {
        config(['telegram.contents_encryption_enabled' => true]);
        Queue::fake();
        $user = User::factory()->create();
        $connection = TelegramConnection::factory()->connected()->create(['user_id' => $user->id]);
        $response = $this->actingAs($user)->postJson('/files/upload', [
            'connection_id' => $connection->id, 'file' => UploadedFile::fake()->createWithContent('ordinary.txt', 'ordinary bytes'),
        ])->assertAccepted();
        $this->assertNotNull($connection->refresh()->encryption_locked_at);
        $this->postJson('/file-operations/'.$response->json('operation_id').'/cancel')->assertAccepted();
        File::query()->findOrFail($response->json('file_id'))->forceDelete();

        $this->postJson('/telegram/connections/'.$connection->id.'/encryption', ['envelope' => $this->envelope()])
            ->assertUnprocessable()->assertJsonValidationErrors('envelope');
        $this->assertSame('none', $connection->refresh()->encryption_mode);
    }

    public function test_existing_trashed_files_and_reconfiguration_block_encryption_setup(): void
    {
        config(['telegram.contents_encryption_enabled' => true]);
        $user = User::factory()->create();
        $connection = TelegramConnection::factory()->connected()->create(['user_id' => $user->id]);
        $file = File::factory()->create(['user_id' => $user->id, 'telegram_connection_id' => $connection->id]);
        $file->delete();
        $this->actingAs($user)->postJson('/telegram/connections/'.$connection->id.'/encryption', ['envelope' => $this->envelope()])->assertUnprocessable();
        $empty = TelegramConnection::factory()->connected()->create(['user_id' => $user->id]);
        $url = '/telegram/connections/'.$empty->id.'/encryption';
        $this->postJson($url, ['envelope' => $this->envelope()])->assertOk();
        $this->postJson($url, ['envelope' => $this->envelope()])->assertUnprocessable();
    }

    #[DataProvider('invalidUploads')]
    public function test_invalid_encrypted_upload_creates_no_reservation_or_temporary_file(string $case): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $connection = TelegramConnection::factory()->connected()->create(['user_id' => $user->id, 'encryption_mode' => $case === 'ordinary' ? 'none' : 'contents']);
        $bytes = $this->ciphertext($user, $connection, 'protected bytes');
        $encrypted = true;
        if ($case === 'plaintext') {
            $bytes = 'plaintext must not be accepted';
            $encrypted = false;
        } elseif ($case === 'truncated') {
            $bytes = substr($bytes, 0, -1);
        } elseif ($case === 'foreign-header') {
            $bytes = substr_replace($bytes, pack('J', $connection->id + 1), 48, 8);
        } elseif ($case === 'unknown-version') {
            $bytes = substr_replace($bytes, '2', 7, 1);
        } elseif ($case === 'overhead') {
            config(['telegram.storage.max_user_bytes' => 15]);
        }

        $this->actingAs($user)->postJson('/files/upload', ['connection_id' => $connection->id, 'encrypted' => $encrypted,
            'file' => UploadedFile::fake()->createWithContent('protected.bin', $bytes)])->assertUnprocessable();

        $this->assertDatabaseCount('files', 0);
        $this->assertDatabaseCount('file_operations', 0);
        $this->assertNull($connection->refresh()->encryption_locked_at);
        $this->assertSame([], Storage::disk('local')->allFiles('telegram-upload'));
        Queue::assertNothingPushed();
    }

    public static function invalidUploads(): array
    {
        return array_map(fn (string $case): array => [$case], ['plaintext', 'truncated', 'foreign-header', 'unknown-version', 'ordinary', 'overhead']);
    }

    public function test_queued_encrypted_upload_can_be_cancelled_and_foreign_operations_are_denied(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $foreign = User::factory()->create();
        $connection = TelegramConnection::factory()->connected()->create(['user_id' => $user->id, 'encryption_mode' => 'contents']);
        $bytes = $this->ciphertext($user, $connection, 'private bytes');
        $this->actingAs($foreign)->postJson('/files/upload', ['connection_id' => $connection->id, 'encrypted' => true,
            'file' => UploadedFile::fake()->createWithContent('private.bin', $bytes)])->assertForbidden();
        $response = $this->actingAs($user)->postJson('/files/upload', ['connection_id' => $connection->id, 'encrypted' => true,
            'file' => UploadedFile::fake()->createWithContent('private.bin', $bytes)])->assertAccepted();
        $id = $response->json('file_id');
        $operation = $response->json('operation_id');
        $this->actingAs($foreign)->postJson('/files/'.$id.'/prepare-download')->assertForbidden();
        $this->getJson('/files/'.$id.'/download')->assertForbidden();
        $this->postJson('/file-operations/'.$operation.'/cancel')->assertForbidden();
        $this->actingAs($user)->postJson('/file-operations/'.$operation.'/cancel')->assertAccepted();
        $this->assertSame([], Storage::disk('local')->allFiles('telegram-upload'));
        $this->assertSame('cancelled', FileOperation::query()->findOrFail($operation)->status->value);
    }

    public function test_copy_cannot_bypass_encryption_and_move_keeps_the_same_connection(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $connection = TelegramConnection::factory()->connected()->create(['user_id' => $user->id, 'encryption_mode' => 'contents']);
        $file = File::factory()->create(['user_id' => $user->id, 'telegram_connection_id' => $connection->id, 'encryption_header' => base64_encode(str_repeat('h', 64))]);

        $this->actingAs($user)->postJson('/files/'.$file->id.'/copy', [])->assertUnprocessable();
        $this->patchJson('/files/'.$file->id.'/move', ['connection_id' => 999])->assertUnprocessable();
        $this->postJson('/files/bulk/move', ['file_ids' => [$file->id], 'connection_id' => 999])->assertUnprocessable();
        $this->patchJson('/files/'.$file->id.'/move', ['directory_id' => null])->assertOk();
        $this->assertSame($connection->id, $file->refresh()->telegram_connection_id);
        $this->assertDatabaseCount('files', 1);
        Queue::assertNothingPushed();
    }

    public function test_disabled_encrypted_connections_remain_unlockable_for_downloads_without_allowing_uploads(): void
    {
        $user = User::factory()->create();
        $connection = TelegramConnection::factory()->connected()->create(['user_id' => $user->id,
            'status' => 'disabled', 'encryption_mode' => 'contents', 'vault_envelope' => $this->envelope()]);
        TelegramConnection::factory()->connected()->create(['encryption_mode' => 'contents', 'vault_envelope' => $this->envelope()]);

        $this->actingAs($user)->get('/files')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('connections', 0)->has('vault_connections', 1)
            ->where('vault_connections.0.id', $connection->id)
            ->where('vault_connections.0.vault_envelope', $this->envelope()));
        $this->postJson('/files/upload', ['connection_id' => $connection->id, 'file' => UploadedFile::fake()->createWithContent('denied', 'bytes')])->assertForbidden();
    }

    public function test_owner_can_rewrap_a_locked_disabled_vault_without_changing_files_or_encryption_mode(): void
    {
        $user = User::factory()->create();
        $previous = $this->envelope();
        $connection = TelegramConnection::factory()->connected()->create([
            'user_id' => $user->id, 'status' => 'disabled', 'encryption_mode' => 'contents',
            'vault_envelope' => $previous, 'encryption_locked_at' => now(),
        ]);
        $file = File::factory()->create(['user_id' => $user->id, 'telegram_connection_id' => $connection->id]);
        $attributes = $file->refresh()->getAttributes();
        $lockedAt = $connection->encryption_locked_at;
        $replacement = $previous;
        $replacement['salt'] = base64_encode(str_repeat('n', 16));
        $replacement['password']['ciphertext'] = base64_encode(str_repeat('p', 48));
        $url = '/telegram/connections/'.$connection->id.'/encryption';

        $this->actingAs($user)->postJson($url, ['envelope' => $replacement, 'previous_envelope' => $previous])
            ->assertOk()->assertJsonPath('encryption_mode', 'contents');
        $this->assertSame($replacement, $connection->refresh()->vault_envelope);
        $this->assertEquals($lockedAt, $connection->encryption_locked_at);
        $this->assertSame($attributes, $file->refresh()->getAttributes());
        $this->assertSame('disabled', $connection->status->value);

        $recovery = $replacement;
        $recovery['recovery']['ciphertext'] = base64_encode(str_repeat('r', 48));
        $this->postJson($url, ['envelope' => $recovery, 'previous_envelope' => $replacement])->assertOk();
        $this->assertSame($recovery, $connection->refresh()->vault_envelope);
        $this->postJson($url, ['envelope' => $replacement, 'previous_envelope' => $previous])
            ->assertUnprocessable()->assertJsonValidationErrors('envelope');
        $this->assertSame($recovery, $connection->refresh()->vault_envelope);
        $this->get('/files')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('vault_connections.0.vault_envelope', $recovery));
        $this->get('/telegram/connections')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('connections.0.vault_envelope', $recovery));
    }

    public function test_vault_updates_reject_foreign_accounts_plaintext_and_missing_or_stale_material(): void
    {
        $user = User::factory()->create();
        $foreign = User::factory()->create();
        $envelope = $this->envelope();
        $connection = TelegramConnection::factory()->connected()->create([
            'user_id' => $user->id, 'encryption_mode' => 'contents', 'vault_envelope' => $envelope,
        ]);
        $url = '/telegram/connections/'.$connection->id.'/encryption';
        $payload = ['envelope' => $envelope, 'previous_envelope' => $envelope];
        $this->postJson($url, $payload)->assertUnauthorized();
        $this->actingAs($foreign)->postJson($url, $payload)->assertForbidden();
        $this->actingAs($user)->postJson($url, ['envelope' => $envelope])->assertUnprocessable();
        $this->postJson($url, ['envelope' => $envelope, 'previous_envelope' => []])->assertUnprocessable();
        $bad = $envelope;
        $bad['recovery']['raw_key'] = 'plaintext secret';
        $this->postJson($url, ['envelope' => $bad, 'previous_envelope' => $envelope])
            ->assertUnprocessable()->assertJsonValidationErrors('envelope.recovery');
        $this->assertSame($envelope, $connection->refresh()->vault_envelope);
        $ordinary = TelegramConnection::factory()->connected()->create(['user_id' => $user->id]);
        $this->postJson('/telegram/connections/'.$ordinary->id.'/encryption', $payload)->assertUnprocessable();
        $this->assertSame('none', $ordinary->refresh()->encryption_mode);
        $this->assertNull($ordinary->vault_envelope);
    }

    #[DataProvider('invalidVaultUpdates')]
    public function test_invalid_vault_replacements_preserve_the_previous_material(string $case): void
    {
        $user = User::factory()->create();
        $previous = $this->envelope();
        $replacement = $previous;
        $stored = $case === 'missing-material' ? null : $previous;
        $connection = TelegramConnection::factory()->connected()->create([
            'user_id' => $user->id, 'encryption_mode' => 'contents', 'vault_envelope' => $stored,
        ]);
        if ($case === 'both-wraps') {
            $replacement['password']['ciphertext'] = base64_encode(str_repeat('n', 48));
            $replacement['recovery']['ciphertext'] = base64_encode(str_repeat('n', 48));
        } elseif ($case === 'invalid-ciphertext') {
            $replacement['password']['ciphertext'] = 'plaintext';
        } elseif ($case === 'missing-recovery') {
            unset($replacement['recovery']);
        }
        $this->actingAs($user)->postJson('/telegram/connections/'.$connection->id.'/encryption', [
            'envelope' => $replacement, 'previous_envelope' => $previous,
        ])->assertUnprocessable();
        $this->assertSame($stored, $connection->refresh()->vault_envelope);
    }

    public static function invalidVaultUpdates(): array
    {
        return [['both-wraps'], ['invalid-ciphertext'], ['missing-recovery'], ['missing-material']];
    }

    public function test_resetting_the_account_password_does_not_replace_or_unlock_the_vault(): void
    {
        $user = User::factory()->create();
        $connection = TelegramConnection::factory()->connected()->create([
            'user_id' => $user->id, 'encryption_mode' => 'contents', 'vault_envelope' => $this->envelope(),
        ]);
        $token = Password::broker()->createToken($user);
        $this->post(route('password.update'), [
            'token' => $token, 'email' => $user->email,
            'password' => 'new account password', 'password_confirmation' => 'new account password',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('new account password', $user->refresh()->password));
        $this->assertSame($this->envelope(), $connection->refresh()->vault_envelope);
        $this->postJson('/telegram/connections/'.$connection->id.'/encryption', ['envelope' => $this->envelope()])->assertUnauthorized();
    }

    public function test_metadata_protected_uploads_keep_only_opaque_names_and_ciphertext_metadata(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $connection = TelegramConnection::factory()->connected()->create(['user_id' => $user->id, 'encryption_mode' => 'contents_and_metadata', 'vault_envelope' => $this->envelope()]);
        $metadata = ['version' => 1, 'salt' => base64_encode(str_repeat('s', 32)), 'iv' => base64_encode(str_repeat('i', 12)), 'ciphertext' => base64_encode(str_repeat('c', 64))];
        $payload = ['connection_id' => $connection->id, 'encrypted' => true, 'plaintext_mime' => null, 'protected_metadata' => $metadata,
            'file' => UploadedFile::fake()->createWithContent('opaque-file-1', $this->ciphertext($user, $connection, 'secret bytes'))];
        $response = $this->actingAs($user)->postJson('/files/upload', $payload)->assertAccepted();
        $file = File::findOrFail($response->json('file_id'));
        $this->assertSame('opaque-file-1', $file->name);
        $this->assertNull($file->extension);
        $this->assertSame(['protected_metadata' => $metadata], $file->metadata);
        $this->assertNotNull($connection->refresh()->encryption_locked_at);
        $this->patchJson('/files/'.$file->id.'/rename', ['name' => 'private.pdf'])->assertUnprocessable();
        $this->patchJson('/files/'.$file->id.'/description', ['description' => 'private description'])->assertUnprocessable();
        $this->postJson('/files/'.$file->id.'/tags', ['tags' => ['private']])->assertUnprocessable();
        $this->assertDatabaseMissing('tags', ['name' => 'private']);
        $this->postJson('/files/upload', array_merge($payload, ['plaintext_mime' => 'application/pdf']))->assertUnprocessable();
        $this->postJson('/files/upload', array_merge($payload, ['file' => UploadedFile::fake()->createWithContent('private.pdf', $this->ciphertext($user, $connection, 'secret bytes'))]))->assertUnprocessable();
    }

    public function test_owner_can_choose_metadata_scope_and_rewrap_without_changing_it(): void
    {
        config(['telegram.contents_encryption_enabled' => true]);
        $user = User::factory()->create();
        $connection = TelegramConnection::factory()->connected()->create(['user_id' => $user->id]);
        $url = '/telegram/connections/'.$connection->id.'/encryption';

        $this->actingAs($user)->postJson($url, ['scope' => 'contents_and_metadata', 'envelope' => $this->envelope()])
            ->assertOk()->assertJsonPath('encryption_mode', 'contents_and_metadata');
        $this->assertSame('contents_and_metadata', $connection->refresh()->encryption_mode);
        $this->postJson($url, ['scope' => 'contents', 'previous_envelope' => $this->envelope(), 'envelope' => $this->envelope()])->assertUnprocessable();
        $this->postJson($url, ['previous_envelope' => $this->envelope(), 'envelope' => $this->envelope()])->assertOk();
        $this->assertSame('contents_and_metadata', $connection->refresh()->encryption_mode);
        $this->get('/files')->assertInertia(fn (AssertableInertia $page) => $page->has('vault_connections', 1));
    }

    public function test_protected_metadata_edits_preserve_opaque_identity_and_reject_stale_or_foreign_writes(): void
    {
        $owner = User::factory()->create();
        $connection = TelegramConnection::factory()->connected()->create(['user_id' => $owner->id, 'encryption_mode' => 'contents_and_metadata']);
        $before = ['version' => 1, 'salt' => base64_encode(str_repeat('s', 32)), 'iv' => base64_encode(str_repeat('i', 12)), 'ciphertext' => base64_encode(str_repeat('c', 64))];
        $after = array_replace($before, ['ciphertext' => base64_encode(str_repeat('d', 64))]);
        $file = File::factory()->uploaded()->create(['user_id' => $owner->id, 'telegram_connection_id' => $connection->id, 'telegram_message_id' => 90, 'name' => 'opaque-id', 'original_name' => 'opaque-id', 'extension' => null, 'mime_type' => 'application/octet-stream', 'encryption_header' => base64_encode(str_repeat('h', 64)), 'metadata' => ['protected_metadata' => $before]]);
        $url = '/files/'.$file->id.'/protected-metadata';
        $payload = ['protected_metadata' => $after, 'previous_metadata' => $before];
        Queue::fake([TelegramOperationJob::class]);

        $this->patchJson($url, $payload)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->patchJson($url, $payload)->assertForbidden();
        $this->assertSame(0, FileOperation::query()->where('file_id', $file->id)->where('operation', 'metadata')->count());
        $this->actingAs($owner)->patchJson($url, array_merge($payload, ['name' => 'secret.pdf']))->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson($url, $payload)->assertOk();
        $this->assertSame(['protected_metadata' => $after], $file->refresh()->metadata);
        $metadataOperation = FileOperation::query()->where('file_id', $file->id)->where('operation', 'metadata')->firstOrFail();
        $this->assertSame([], $metadataOperation->metadata);
        $this->assertSame('opaque-id', $file->name);
        $this->patchJson($url, $payload)->assertConflict();
        $this->assertSame(['protected_metadata' => $after], $file->refresh()->metadata);
        $this->assertDatabaseCount('tags', 0);
    }

    public function test_owner_catalog_returns_encrypted_metadata_and_readable_folder_paths_without_cross_account_files(): void
    {
        $owner = User::factory()->create();
        $connection = TelegramConnection::factory()->connected()->create(['user_id' => $owner->id, 'encryption_mode' => 'contents_and_metadata']);
        $directory = Directory::factory()->create(['user_id' => $owner->id, 'name' => 'Readable', 'path' => 'Readable']);
        $metadata = ['version' => 1, 'salt' => base64_encode(str_repeat('s', 32)), 'iv' => base64_encode(str_repeat('i', 12)), 'ciphertext' => base64_encode(str_repeat('c', 64))];
        $file = File::factory()->create(['user_id' => $owner->id, 'telegram_connection_id' => $connection->id, 'directory_id' => $directory->id, 'name' => 'opaque-id', 'original_name' => 'opaque-id', 'extension' => null, 'mime_type' => 'application/octet-stream', 'metadata' => ['protected_metadata' => $metadata]]);
        File::factory()->create();

        $this->actingAs($owner)->getJson('/files?scope=all&telegram_connection_id='.$connection->id)->assertOk()
            ->assertJsonPath('files.total', 1)->assertJsonPath('files.data.0.storage_name', 'opaque-id')
            ->assertJsonPath('files.data.0.metadata_protected', true)->assertJsonPath('files.data.0.protected_metadata', $metadata)
            ->assertJsonPath('files.data.0.directory_path', 'Readable');
        $this->actingAs(User::factory()->create())->getJson('/files?scope=all&telegram_connection_id='.$connection->id)->assertOk()->assertJsonPath('files.total', 0);
        $this->assertSame('opaque-id', $file->refresh()->name);
    }

    public function test_protected_upload_worker_and_moves_keep_metadata_opaque_and_forbid_copies(): void
    {
        $user = User::factory()->create();
        $connection = TelegramConnection::factory()->connected()->create(['user_id' => $user->id, 'encryption_mode' => 'contents_and_metadata', 'vault_envelope' => $this->envelope()]);
        $directory = Directory::factory()->create(['user_id' => $user->id, 'name' => 'Readable folder', 'path' => 'Readable folder']);
        $driver = new FakeTelegramStorageDriver;
        $this->app->instance(StorageManager::class, new class($driver) extends StorageManager
        {
            public function __construct(private FakeTelegramStorageDriver $driver) {}

            public function for(TelegramConnection $connection): TelegramStorageDriver
            {
                return $this->driver;
            }
        });
        $metadata = ['version' => '1', 'salt' => base64_encode(str_repeat('s', 32)), 'iv' => base64_encode(str_repeat('i', 12)), 'ciphertext' => base64_encode(str_repeat('c', 64))];
        $ciphertext = $this->ciphertext($user, $connection, 'private content');
        $response = $this->actingAs($user)->post('/files/upload', ['connection_id' => $connection->id, 'encrypted' => '1', 'protected_metadata' => $metadata, 'file' => UploadedFile::fake()->createWithContent('opaque-identity', $ciphertext)], ['Accept' => 'application/json'])->assertAccepted();
        $file = File::query()->findOrFail($response->json('file_id'));

        $this->assertSame('uploaded', $file->status->value);
        $this->assertSame(1, $file->metadata['protected_metadata']['version']);
        $this->assertSame('application/octet-stream', $file->mime_type);
        $this->assertSame('other', $file->file_type->value);
        $this->assertNull($file->extension);
        $this->assertSame($ciphertext, $driver->contents[$file->telegram_message_id]);
        $this->assertSame(hash('sha256', $ciphertext), $file->checksum);
        $this->assertStringNotContainsString('private', json_encode($file->getAttributes()));
        $this->assertStringNotContainsString('private', json_encode(FileOperation::query()->firstOrFail()->metadata));
        $this->patchJson('/files/'.$file->id.'/move', ['directory_id' => $directory->id])->assertOk();
        $this->assertSame('opaque-identity', $file->refresh()->name);
        $this->assertSame($directory->id, $file->directory_id);
        $this->postJson('/files/'.$file->id.'/copy', ['name' => 'private.pdf'])->assertUnprocessable();
        $this->postJson('/files/bulk/tags', ['file_ids' => [$file->id], 'tags' => ['private-tag']])->assertUnprocessable();
        $this->assertDatabaseCount('files', 1);
        $this->assertDatabaseCount('tags', 0);
        $this->postJson('/telegram/connections/'.$connection->id.'/encryption', ['scope' => 'contents', 'envelope' => $this->envelope()])->assertUnprocessable();
        $this->assertSame('contents_and_metadata', $connection->refresh()->encryption_mode);
    }

    #[DataProvider('encryptionScopes')]
    public function test_both_scopes_keep_content_private_and_project_only_scope_permitted_roxi_metadata(string $scope): void
    {
        $user = User::factory()->create();
        $connection = TelegramConnection::factory()->connected()->create(['user_id' => $user->id, 'method' => 'invite', 'encryption_mode' => $scope]);
        $name = $scope === 'contents_and_metadata' ? 'opaque-storage-id' : 'private <b>&.pdf';
        $bytes = $this->ciphertext($user, $connection, 'never send this plaintext');
        $runtime = $this->partialMock(RoxiRuntime::class);
        $runtime->shouldReceive('uploadFromCallable')->once()->andReturnUsing(function (callable $read, int $size, string $mime, string $storageName, callable $callback, bool $seekable) use ($bytes, $name): array {
            $this->assertSame($bytes, $read(0, $size));
            $this->assertSame('application/octet-stream', $mime);
            $this->assertSame($name, $storageName);

            return ['_' => 'inputFile', 'id' => 10, 'parts' => 1, 'name' => $storageName, 'md5_checksum' => md5($bytes)];
        });
        $runtime->shouldReceive('methodCallAsyncRead')->with('messages.sendMedia', \Mockery::on(function (array $args) use ($name, $scope): bool {
            $this->assertSame('application/octet-stream', $args['media']['mime_type']);
            $this->assertSame($name, $args['media']['attributes'][0]['file_name']);
            if ($scope === 'contents_and_metadata') {
                $this->assertStringContainsString('ID: opaque-storage-id', $args['message']);
                $this->assertStringNotContainsString('private', $args['message']);
            } else {
                $this->assertStringContainsString($name, $args['message']);
            }
            $this->assertStringContainsString('/files?file=', $args['message']);

            return true;
        }))->once()->andReturn(['_' => 'updateShortSentMessage', 'id' => 901]);
        $payload = ['connection_id' => $connection->id, 'encrypted' => true, 'file' => UploadedFile::fake()->createWithContent($name, $bytes)];
        if ($scope === 'contents_and_metadata') {
            $payload['protected_metadata'] = ['version' => 1, 'salt' => base64_encode(str_repeat('s', 32)), 'iv' => base64_encode(str_repeat('i', 12)), 'ciphertext' => base64_encode(str_repeat('c', 64))];
        } else {
            $payload['plaintext_mime'] = 'application/pdf';
        }

        $response = $this->actingAs($user)->postJson('/files/upload', $payload)->assertAccepted();
        $file = File::query()->findOrFail($response->json('file_id'));
        $this->assertSame('uploaded', $file->status->value);
        $this->assertSame($name, $file->name);
        if ($scope === 'contents_and_metadata') {
            $this->assertStringNotContainsString('private', json_encode($file->getAttributes()));
        }
    }

    /** @return array<string, array{string}> */
    public static function encryptionScopes(): array
    {
        return ['contents only' => ['contents'], 'contents and metadata' => ['contents_and_metadata']];
    }

    #[DataProvider('encryptionScopes')]
    public function test_bot_uploads_publish_only_the_scope_permitted_filename_and_ciphertext(string $scope): void
    {
        $user = User::factory()->create();
        $account = TelegramAccount::factory()->create(['user_id' => $user->id, 'method' => 'bot', 'credentials' => '12345:test']);
        $connection = TelegramConnection::factory()->connected()->create(['user_id' => $user->id, 'telegram_account_id' => $account->id, 'method' => 'bot', 'encryption_mode' => $scope]);
        $sentBody = null;
        Http::fake(['api.telegram.org/bot12345:test/sendDocument' => function ($request) use (&$sentBody) {
            $sentBody = $request->body();

            return Http::response(['ok' => true, 'result' => ['message_id' => 901, 'document' => ['file_id' => 'document-id', 'file_unique_id' => 'unique-id']]]);
        }]);
        $name = $scope === 'contents_and_metadata' ? 'opaque-storage-id' : 'private <b>&.pdf';
        $bytes = $this->ciphertext($user, $connection, 'never send this plaintext');
        $payload = ['connection_id' => $connection->id, 'encrypted' => true, 'file' => UploadedFile::fake()->createWithContent($name, $bytes)];
        if ($scope === 'contents_and_metadata') {
            $payload['protected_metadata'] = ['version' => 1, 'salt' => base64_encode(str_repeat('s', 32)), 'iv' => base64_encode(str_repeat('i', 12)), 'ciphertext' => base64_encode(str_repeat('c', 64))];
        } else {
            $payload['plaintext_mime'] = 'application/pdf';
        }

        $response = $this->actingAs($user)->postJson('/files/upload', $payload)->assertAccepted();
        $file = File::query()->findOrFail($response->json('file_id'));
        $this->assertSame('uploaded', $file->status->value);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendDocument'));
        $this->assertIsString($sentBody);
        $this->assertStringContainsString($name, $sentBody);
        $this->assertStringContainsString($bytes, $sentBody);
        $this->assertStringNotContainsString('never send this plaintext', $sentBody);
        $this->assertStringNotContainsString('protected_metadata', $sentBody);
        if ($scope === 'contents_and_metadata') {
            $this->assertStringNotContainsString('private', $sentBody);
        }
    }

    #[DataProvider('optionalMetadataScopes')]
    public function test_optional_empty_multipart_metadata_fields_do_not_break_uploads(string $scope): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $connection = TelegramConnection::factory()->connected()->create(['user_id' => $user->id, 'encryption_mode' => $scope]);
        $encrypted = $scope !== 'none';
        $bytes = $encrypted ? $this->ciphertext($user, $connection, 'file contents') : 'file contents';

        $this->actingAs($user)->post('/files/upload', [
            'connection_id' => $connection->id, 'encrypted' => $encrypted ? '1' : '0',
            'protected_metadata' => '', 'plaintext_mime' => $encrypted ? 'text/plain' : '',
            'file' => UploadedFile::fake()->createWithContent('file.txt', $bytes),
        ], ['Accept' => 'application/json'])->assertAccepted();
    }

    /** @return array<string, array{string}> */
    public static function optionalMetadataScopes(): array
    {
        return ['ordinary' => ['none'], 'contents only' => ['contents']];
    }

    private function ciphertext(User $user, TelegramConnection $connection, string $plaintext): string
    {
        $salt = str_repeat('s', 32);
        $header = 'ROXIF001'.$salt.pack('J', strlen($plaintext)).pack('J', $connection->id).pack('N', 1048576).pack('N', 0);
        $key = hash_hkdf('sha256', str_repeat('k', 32), 32, 'roxifile:vault:1:'.$user->id.':'.$connection->id.':contents:1', $salt);
        $encrypted = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, str_repeat("\0", 12), $tag, $header.pack('N', 0));

        return $header.$encrypted.$tag;
    }

    private function envelope(): array
    {
        return ['version' => 1, 'salt' => base64_encode(str_repeat('s', 16)), 'iterations' => 600000,
            'password' => ['iv' => base64_encode(str_repeat('i', 12)), 'ciphertext' => base64_encode(str_repeat('c', 48))],
            'recovery' => ['iv' => base64_encode(str_repeat('r', 12)), 'ciphertext' => base64_encode(str_repeat('w', 48))]];
    }
}
