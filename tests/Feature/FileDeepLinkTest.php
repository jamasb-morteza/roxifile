<?php

namespace Tests\Feature;

use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\TestWith;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class FileDeepLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_owned_nested_file_is_selected_independently_of_the_listing_filters_and_page(): void
    {
        $user = User::factory()->create();
        $directory = Directory::factory()->create(['user_id' => $user->id, 'name' => 'Nested']);
        $file = File::factory()->uploaded()->create(['user_id' => $user->id, 'directory_id' => $directory->id, 'name' => 'linked.txt']);

        $response = $this->actingAs($user)->get('/files?file='.$file->uuid.'&q=absent&page=20');

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('files/Index')
            ->where('selected_file.id', $file->id)
            ->where('selected_file.uuid', $file->uuid)
            ->where('selected_file.directory_id', $directory->id)
            ->where('selected_file.directory_path', 'Nested')
            ->where('selected_file.name', 'linked.txt')
            ->has('files.data', 0));
    }

    public function test_foreign_missing_and_deleted_link_targets_return_not_found_without_metadata(): void
    {
        $user = User::factory()->create();
        $foreign = File::factory()->uploaded()->create(['description' => 'private foreign description']);
        $deleted = File::factory()->uploaded()->create(['user_id' => $user->id]);
        $deleted->delete();
        $this->actingAs($user);

        foreach ([$foreign->uuid, $deleted->uuid, (string) Str::uuid7()] as $id) {
            $this->get('/files?file='.$id)->assertNotFound()->assertDontSee('private foreign description');
        }
    }

    public function test_new_files_have_unique_time_ordered_uuidv7_public_identifiers(): void
    {
        $user = User::factory()->create();
        $first = File::factory()->for($user)->create();
        $second = File::factory()->for($user)->create();

        $this->assertSame(7, Uuid::fromString($first->uuid)->getVersion());
        $this->assertSame(7, Uuid::fromString($second->uuid)->getVersion());
        $this->assertNotSame($first->uuid, $second->uuid);
        $this->assertSame($first->uuid, $first->fresh()->uuid);
    }

    public function test_legacy_numeric_links_redirect_to_the_owned_uuid_while_preserving_filters(): void
    {
        $user = User::factory()->create();
        $file = File::factory()->for($user)->create();

        $this->actingAs($user)
            ->get('/files?file='.$file->id.'&q=report&page=2')
            ->assertRedirect(route('files.index', ['file' => $file->uuid, 'q' => 'report', 'page' => 2]));
    }

    public function test_foreign_legacy_numeric_links_do_not_redirect_or_expose_file_ids(): void
    {
        $user = User::factory()->create();
        $foreign = File::factory()->create(['description' => 'private foreign description']);

        $this->actingAs($user)->get('/files?file='.$foreign->id)
            ->assertNotFound()
            ->assertDontSee('private foreign description');
    }

    public function test_file_links_require_authentication(): void
    {
        $file = File::factory()->uploaded()->create();

        $this->get('/files?file='.$file->uuid)->assertRedirect(route('login'));
    }

    public function test_file_without_a_connection_uses_unencrypted_metadata_defaults(): void
    {
        $user = User::factory()->create();
        $file = File::factory()->for($user)->create();

        $response = $this->actingAs($user)->getJson('/files?file='.$file->uuid);

        $response->assertOk()
            ->assertJsonPath('selected_file.encryption_mode', 'none')
            ->assertJsonPath('selected_file.vault_envelope', null)
            ->assertJsonPath('selected_file.metadata_protected', false);
    }

    public function test_json_file_snapshot_includes_only_wrapped_vault_material_for_encrypted_copies(): void
    {
        $user = User::factory()->create();
        $envelope = [
            'version' => 1,
            'salt' => 'salt',
            'iterations' => 600000,
            'password' => ['iv' => 'password-iv', 'ciphertext' => 'wrapped-password-key'],
            'recovery' => ['iv' => 'recovery-iv', 'ciphertext' => 'wrapped-recovery-key'],
        ];
        $connection = TelegramConnection::factory()->connected()->create([
            'user_id' => $user->id,
            'encryption_mode' => 'contents_and_metadata',
            'vault_envelope' => $envelope,
        ]);
        $file = File::factory()->uploaded()->create([
            'user_id' => $user->id,
            'telegram_account_id' => $connection->telegram_account_id,
            'telegram_connection_id' => $connection->id,
            'name' => 'opaque-file-1',
            'mime_type' => 'application/octet-stream',
            'description' => null,
            'metadata' => ['protected_metadata' => ['version' => 1, 'ciphertext' => 'sealed metadata']],
            'encryption_header' => 'ROXIF001-header',
        ]);

        $response = $this->actingAs($user)->getJson('/files?file='.$file->uuid);

        $response->assertOk()
            ->assertJsonPath('selected_file.encryption_mode', 'contents_and_metadata')
            ->assertJsonPath('selected_file.vault_envelope.password.ciphertext', 'wrapped-password-key')
            ->assertJsonPath('selected_file.vault_envelope.recovery.ciphertext', 'wrapped-recovery-key')
            ->assertJsonPath('selected_file.protected_metadata.ciphertext', 'sealed metadata')
            ->assertJsonPath('selected_file.name', 'opaque-file-1')
            ->assertDontSee('raw-key');
    }

    public function test_json_file_snapshot_keeps_contents_scope_mime_available_without_plaintext_keys(): void
    {
        $user = User::factory()->create();
        $envelope = [
            'version' => 1,
            'salt' => 'salt',
            'iterations' => 600000,
            'password' => ['iv' => 'password-iv', 'ciphertext' => 'wrapped-password-key'],
            'recovery' => ['iv' => 'recovery-iv', 'ciphertext' => 'wrapped-recovery-key'],
        ];
        $connection = TelegramConnection::factory()->connected()->create([
            'user_id' => $user->id,
            'encryption_mode' => 'contents',
            'vault_envelope' => $envelope,
        ]);
        $file = File::factory()->uploaded()->create([
            'user_id' => $user->id,
            'telegram_account_id' => $connection->telegram_account_id,
            'telegram_connection_id' => $connection->id,
            'name' => 'photo.jpg',
            'mime_type' => 'application/octet-stream',
            'metadata' => ['plaintext_mime' => 'image/jpeg'],
            'encryption_header' => 'ROXIF001-header',
        ]);

        $response = $this->actingAs($user)->getJson('/files?file='.$file->uuid);

        $response->assertOk()
            ->assertJsonPath('selected_file.encryption_mode', 'contents')
            ->assertJsonPath('selected_file.mime_type', 'image/jpeg')
            ->assertJsonPath('selected_file.metadata_protected', false)
            ->assertJsonPath('selected_file.name', 'photo.jpg');
    }

    #[TestWith(['invalid'])]
    #[TestWith(['0'])]
    public function test_invalid_target_identifiers_are_rejected(string $identifier): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/files?file='.$identifier)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }
}
