<?php

namespace Tests\Feature;

use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\TelegramDownloadStreamToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_plaintext_mtproto_tracks_with_supported_sizes_use_direct_streaming(): void
    {
        $user = User::factory()->create();
        $directory = Directory::factory()->create(['user_id' => $user->id]);
        $roxi = TelegramConnection::factory()->connected()->create(['user_id' => $user->id, 'method' => TelegramMethod::Roxi]);
        $invite = TelegramConnection::factory()->connected()->create(['user_id' => $user->id, 'method' => TelegramMethod::Invite]);
        $bot = TelegramConnection::factory()->connected()->create(['user_id' => $user->id, 'method' => TelegramMethod::Bot]);
        $personal = TelegramConnection::factory()->connected()->create(['user_id' => $user->id, 'method' => TelegramMethod::Personal]);
        $protected = TelegramConnection::factory()->connected()->create(['user_id' => $user->id, 'method' => TelegramMethod::Roxi, 'encryption_mode' => 'contents']);

        foreach ([
            ['01-roxi.mp3', $roxi, 1024, null],
            ['02-invite.mp3', $invite, TelegramDownloadStreamToken::MAX_FILE_BYTES, null],
            ['03-bot.mp3', $bot, 1024, null],
            ['04-personal.mp3', $personal, 1024, null],
            ['05-encrypted.mp3', $roxi, 1024, 'cipher-header'],
            ['06-oversize.mp3', $roxi, TelegramDownloadStreamToken::MAX_FILE_BYTES + 1, null],
            ['07-empty.mp3', $roxi, 0, null],
            ['08-protected.mp3', $protected, 1024, null],
        ] as [$name, $connection, $size, $header]) {
            File::factory()->uploaded()->create([
                'user_id' => $user->id,
                'directory_id' => $directory->id,
                'telegram_connection_id' => $connection->id,
                'name' => $name,
                'file_type' => 'audio',
                'mime_type' => 'audio/mpeg',
                'size' => $size,
                'encryption_header' => $header,
            ]);
        }

        $this->withoutVite()->actingAs($user);
        $this->patchJson('/media/library', ['directory_id' => $directory->id])->assertOk();

        $this->get('/media')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('tracks', 8)
            ->where('tracks.0.streamable', true)
            ->where('tracks.1.streamable', true)
            ->where('tracks.2.streamable', false)
            ->where('tracks.3.streamable', false)
            ->where('tracks.4.streamable', false)
            ->where('tracks.5.streamable', false)
            ->where('tracks.6.streamable', false)
            ->where('tracks.7.streamable', false)
            ->etc());
    }

    public function test_history_ranks_actual_track_starts_and_deduplicates_retries_per_account(): void
    {
        $connection = TelegramConnection::factory()->connected()->create();
        [$frequent, $recent, $unplayed] = File::factory()->uploaded()->count(3)->create([
            'user_id' => $connection->user_id,
            'telegram_account_id' => $connection->telegram_account_id,
            'telegram_connection_id' => $connection->id,
            'file_type' => 'audio',
        ]);
        $this->withoutVite()->actingAs($connection->user);
        $event = ['event_id' => (string) Str::uuid()];
        $this->postJson('/media/tracks/'.$frequent->id.'/playback', $event)->assertNoContent();
        $this->postJson('/media/tracks/'.$frequent->id.'/playback', ['event_id' => (string) Str::uuid()])->assertNoContent();
        $this->travel(1)->second();
        $this->postJson('/media/tracks/'.$recent->id.'/playback', ['event_id' => (string) Str::uuid()])->assertNoContent();
        $this->postJson('/media/tracks/'.$frequent->id.'/playback', $event)->assertNoContent();

        $this->get('/media')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('history.recent', [$recent->id, $frequent->id])
            ->where('history.most', [$frequent->id, $recent->id])
            ->where('history.counts.'.$frequent->id, 2)
            ->has('tracks', 2)->etc());
        $this->assertDatabaseHas('files', ['id' => $unplayed->id, 'telegram_connection_id' => $connection->id]);

        $this->actingAs(User::factory()->create());
        $this->postJson('/media/tracks/'.$frequent->id.'/playback', $event)->assertForbidden();
        $this->get('/media')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('history.recent', [])->where('history.most', [])->has('tracks', 0)->etc());
    }

    public function test_playback_history_excludes_unavailable_tracks(): void
    {
        $user = User::factory()->create();
        $track = File::factory()->uploaded()->create(['user_id' => $user->id, 'file_type' => 'audio']);
        $document = File::factory()->uploaded()->create(['user_id' => $user->id, 'file_type' => 'document']);
        $this->withoutVite()->actingAs($user);

        $this->postJson('/media/tracks/'.$track->id.'/playback', [])->assertUnprocessable();
        $event = ['event_id' => (string) Str::uuid()];
        $this->postJson('/media/tracks/'.$document->id.'/playback', $event)->assertNotFound();
        $this->postJson('/media/tracks/'.$track->id.'/playback', $event)->assertNoContent();
        $track->delete();

        $this->get('/media')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('history.recent', [])->where('history.most', [])->etc());
    }

    public function test_library_discovers_nested_media_and_persists_playlist_and_preferences(): void
    {
        $user = User::factory()->create();
        $directory = Directory::factory()->create(['user_id' => $user->id]);
        $child = Directory::factory()->create(['user_id' => $user->id, 'parent_id' => $directory->id]);
        $track = File::factory()->uploaded()->create(['user_id' => $user->id, 'directory_id' => $child->id, 'file_type' => 'audio', 'mime_type' => 'audio/mpeg']);
        File::factory()->uploaded()->create(['user_id' => $user->id, 'file_type' => 'audio']);
        $this->withoutVite()->actingAs($user);
        $this->patchJson('/media/library', ['directory_id' => $directory->id])->assertOk();
        $id = $this->postJson('/media/playlists', ['name' => 'Mix'])->assertCreated()->json('id');
        $this->patchJson('/media/playlists/'.$id, ['name' => 'My Mix', 'add_file_id' => $track->id])->assertOk();
        $this->patchJson('/media/playlists/'.$id, ['add_file_id' => $track->id])->assertOk();
        $this->patchJson('/media/tracks/'.$track->id, ['favorite' => true, 'rating' => 10])->assertOk();

        $this->get('/media')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('media/Index')->has('tracks', 1)->where('tracks.0.id', $track->id)
            ->where('tracks.0.favorite', true)->where('tracks.0.rating', 10)
            ->where('playlists.0.name', 'My Mix')->where('playlists.0.file_ids', [$track->id]));
    }

    public function test_bulk_playlist_append_preserves_existing_and_submitted_order(): void
    {
        $user = User::factory()->create();
        [$first, $second, $third] = File::factory()->uploaded()->count(3)->create(['user_id' => $user->id, 'file_type' => 'audio'])->modelKeys();
        $this->withoutVite()->actingAs($user);
        $playlist = $this->postJson('/media/playlists', ['name' => 'Mix', 'file_ids' => [$first]])->assertCreated()->json('id');

        $this->patchJson('/media/playlists/'.$playlist, ['add_file_ids' => [$third, $first, $second]])->assertOk();
        $this->patchJson('/media/playlists/'.$playlist, ['add_file_ids' => [$third, $first, $second]])->assertOk();

        $this->get('/media')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('playlists.0.file_ids', [$first, $third, $second])->etc());
    }

    public function test_bulk_playlist_append_rejects_the_whole_selection_if_one_track_is_private(): void
    {
        $user = User::factory()->create();
        $owned = File::factory()->uploaded()->create(['user_id' => $user->id, 'file_type' => 'audio']);
        $private = File::factory()->uploaded()->create(['file_type' => 'audio']);
        $this->withoutVite()->actingAs($user);
        $playlist = $this->postJson('/media/playlists', ['name' => 'Mix'])->assertCreated()->json('id');

        $this->patchJson('/media/playlists/'.$playlist, ['add_file_ids' => [$owned->id, $private->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('add_file_ids');

        $this->get('/media')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('playlists.0.file_ids', [])->etc());
    }

    public function test_playlist_order_persists_on_reload_even_with_an_unavailable_track(): void
    {
        $user = User::factory()->create();
        $tracks = File::factory()->uploaded()->count(3)->create(['user_id' => $user->id, 'file_type' => 'audio']);
        [$first, $unavailable, $last] = $tracks->modelKeys();
        $this->withoutVite()->actingAs($user);
        $playlist = $this->postJson('/media/playlists', ['name' => 'Mix', 'file_ids' => [$first, $unavailable, $last]])->assertCreated()->json('id');
        $tracks[1]->delete();

        $this->patchJson('/media/playlists/'.$playlist, ['ordered_file_ids' => [$last, $unavailable, $first]])->assertOk();

        $this->get('/media')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('tracks', 2)->where('playlists.0.file_ids', [$last, $unavailable, $first])->etc());
    }

    public function test_playlist_reorder_cannot_change_membership(): void
    {
        $user = User::factory()->create();
        [$first, $second, $added] = File::factory()->uploaded()->count(3)->create(['user_id' => $user->id, 'file_type' => 'audio'])->modelKeys();
        $this->withoutVite()->actingAs($user);
        $playlist = $this->postJson('/media/playlists', ['name' => 'Mix', 'file_ids' => [$first, $second]])->assertCreated()->json('id');
        $this->patchJson('/media/playlists/'.$playlist, ['add_file_id' => $added])->assertOk();

        $this->patchJson('/media/playlists/'.$playlist, ['ordered_file_ids' => [$second, $first]])
            ->assertUnprocessable()->assertJsonValidationErrors('ordered_file_ids');

        $this->get('/media')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('playlists.0.file_ids', [$first, $second, $added])->etc());
    }

    public function test_owner_can_delete_playlist_and_keep_telegram_backed_file(): void
    {
        $connection = TelegramConnection::factory()->connected()->create();
        $track = File::factory()->uploaded()->create([
            'user_id' => $connection->user_id,
            'telegram_account_id' => $connection->telegram_account_id,
            'telegram_connection_id' => $connection->id,
            'file_type' => 'audio',
        ]);
        $this->withoutVite()->actingAs($connection->user);
        $playlist = $this->postJson('/media/playlists', ['name' => 'Mix', 'file_ids' => [$track->id]])->assertCreated()->json('id');

        $this->deleteJson('/media/playlists/'.$playlist)->assertNoContent();

        $this->get('/media')->assertInertia(fn (AssertableInertia $page) => $page->has('playlists', 0)->etc());
        $this->assertDatabaseHas('files', [
            'id' => $track->id, 'telegram_connection_id' => $connection->id, 'deleted_at' => null,
        ]);
    }

    public function test_nested_folders_and_playlist_moves_preserve_tracks_and_order(): void
    {
        $connection = TelegramConnection::factory()->connected()->create();
        [$first, $second] = File::factory()->uploaded()->count(2)->create([
            'user_id' => $connection->user_id, 'telegram_account_id' => $connection->telegram_account_id,
            'telegram_connection_id' => $connection->id, 'file_type' => 'audio',
        ])->modelKeys();
        $this->withoutVite()->actingAs($connection->user);
        $parent = $this->postJson('/media/folders', ['name' => 'Jazz'])->assertCreated()->json('id');
        $child = $this->postJson('/media/folders', ['name' => 'Albums', 'parent_id' => $parent])->assertCreated()->json('id');
        $newParent = $this->postJson('/media/folders', ['name' => 'Collections'])->assertCreated()->json('id');
        $playlist = $this->postJson('/media/playlists', ['name' => 'Mix', 'file_ids' => [$second, $first]])->assertCreated()->json('id');

        $this->patchJson('/media/folders/'.$child, ['name' => 'Records', 'parent_id' => $newParent])->assertOk();
        $this->patchJson('/media/playlists/'.$playlist, ['folder_id' => $child])->assertOk();

        $this->get('/media')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('folders.1.name', 'Records')->where('folders.1.parent_id', $newParent)
            ->where('playlists.0.folder_id', $child)->where('playlists.0.file_ids', [$second, $first])->has('tracks', 2)->etc());

        $this->deleteJson('/media/folders/'.$newParent)->assertNoContent();
        $this->get('/media')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('folders.1.parent_id', null)->where('playlists.0.folder_id', $child)->etc());

        $this->deleteJson('/media/folders/'.$child)->assertNoContent();
        $this->get('/media')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('playlists.0.folder_id', null)->where('playlists.0.file_ids', [$second, $first])->has('tracks', 2)->etc());
    }

    public function test_folder_moves_reject_cycles_and_cross_account_targets(): void
    {
        $this->actingAs(User::factory()->create());
        $private = $this->postJson('/media/folders', ['name' => 'Private'])->assertCreated()->json('id');
        $privatePlaylist = $this->postJson('/media/playlists', ['name' => 'Private mix'])->assertCreated()->json('id');
        $this->withoutVite()->actingAs(User::factory()->create());
        $parent = $this->postJson('/media/folders', ['name' => 'Parent'])->assertCreated()->json('id');
        $child = $this->postJson('/media/folders', ['name' => 'Child', 'parent_id' => $parent])->assertCreated()->json('id');
        $descendant = $this->postJson('/media/folders', ['name' => 'Grandchild', 'parent_id' => $child])->assertCreated()->json('id');
        $playlist = $this->postJson('/media/playlists', ['name' => 'Mix'])->assertCreated()->json('id');

        $this->patchJson('/media/folders/'.$parent, ['parent_id' => $descendant])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->patchJson('/media/folders/'.$parent, ['parent_id' => $parent])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->patchJson('/media/folders/'.$child, ['parent_id' => $private])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->postJson('/media/folders', ['name' => 'Hidden', 'parent_id' => $private])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->patchJson('/media/playlists/'.$playlist, ['folder_id' => $private])->assertUnprocessable()->assertJsonValidationErrors('folder_id');
        $this->postJson('/media/playlists', ['name' => 'Hidden', 'folder_id' => $private])->assertUnprocessable()->assertJsonValidationErrors('folder_id');
        $this->patchJson('/media/playlists/'.$privatePlaylist, ['folder_id' => $child])->assertNotFound();
        $this->patchJson('/media/folders/'.$private, ['name' => 'Renamed'])->assertNotFound();
        $this->deleteJson('/media/folders/'.$private)->assertNotFound();

        $this->get('/media')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('folders', 3)->where('folders.0.parent_id', null)->where('folders.1.parent_id', $parent)
            ->where('playlists.0.folder_id', null)->etc());
    }

    public function test_media_details_and_owned_artwork_round_trip_without_changing_track_files(): void
    {
        $user = User::factory()->create();
        $track = File::factory()->uploaded()->create(['user_id' => $user->id, 'file_type' => 'audio']);
        $cover = File::factory()->uploaded()->create(['user_id' => $user->id, 'file_type' => 'image', 'mime_type' => 'image/png']);
        $foreign = File::factory()->uploaded()->create(['file_type' => 'image', 'mime_type' => 'image/png']);
        $this->withoutVite()->actingAs($user);
        $playlist = $this->postJson('/media/playlists', ['name' => 'Mix', 'file_ids' => [$track->id]])->assertCreated()->json('id');
        $details = ['title' => 'Title', 'artist' => 'Artist', 'album' => 'Album', 'genre' => 'Genre', 'track_number' => 5, 'lyrics' => 'Words', 'cover_file_id' => $cover->id, 'album_cover_file_id' => $cover->id];
        $this->patchJson('/media/tracks/'.$track->id, ['details' => $details])->assertOk();
        $this->patchJson('/media/playlists/'.$playlist, ['cover_file_id' => $cover->id])->assertOk();
        $this->patchJson('/media/tracks/'.$track->id, ['details' => ['cover_file_id' => $foreign->id]])->assertUnprocessable();
        $this->patchJson('/media/playlists/'.$playlist, ['cover_file_id' => $foreign->id])->assertUnprocessable();
        $this->get('/media')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tracks.0.details', $details)->where('tracks.0.name', $track->name)
            ->where('playlists.0.cover_file_id', $cover->id)->where('artwork.0.id', $cover->id)->etc());
    }

    public function test_metadata_protected_tracks_reject_plaintext_details(): void
    {
        $connection = TelegramConnection::factory()->connected()->create(['encryption_mode' => 'contents_and_metadata']);
        $track = File::factory()->uploaded()->create([
            'user_id' => $connection->user_id,
            'telegram_account_id' => $connection->telegram_account_id,
            'telegram_connection_id' => $connection->id,
            'file_type' => 'audio',
        ]);
        $this->withoutVite()->actingAs($connection->user);

        $this->patchJson('/media/tracks/'.$track->id, ['details' => ['lyrics' => 'Private lyrics']])
            ->assertUnprocessable()->assertJsonValidationErrors('details');

        $this->assertSame($track->metadata, $track->fresh()->metadata);
        $this->patchJson('/media/tracks/'.$track->id, ['favorite' => true])->assertOk();
        $this->get('/media')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tracks.0.protected', true)->where('tracks.0.details', null)->etc());
    }

    public function test_smart_playlist_membership_changes_with_rules_and_source_data(): void
    {
        $user = User::factory()->create();
        $directory = Directory::factory()->create(['user_id' => $user->id]);
        $tracks = File::factory()->uploaded()->count(2)->create([
            'user_id' => $user->id,
            'directory_id' => $directory->id,
            'file_type' => 'audio',
        ]);
        [$first, $second] = $tracks->modelKeys();
        $this->withoutVite()->actingAs($user);
        $this->patchJson('/media/library', ['directory_id' => $directory->id])->assertOk();
        $this->patchJson('/media/tracks/'.$first, ['rating' => 8, 'favorite' => true])->assertOk();
        $playlist = $this->postJson('/media/playlists', [
            'name' => 'Top tracks',
            'smart_rules' => ['minimum_rating' => 7, 'favorite_only' => true],
        ])->assertCreated()->json('id');

        $this->get('/media')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('playlists.0.file_ids', [$first])->etc());
        $this->patchJson('/media/tracks/'.$first, ['favorite' => false])->assertOk();
        $this->patchJson('/media/tracks/'.$second, ['rating' => 9, 'favorite' => true])->assertOk();
        $this->patchJson('/media/playlists/'.$playlist, ['add_file_id' => $first])
            ->assertUnprocessable()->assertJsonValidationErrors('file_ids');
        $this->get('/media')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('playlists.0.file_ids', [$second])->etc());

        $this->patchJson('/media/playlists/'.$playlist, ['smart_rules' => ['minimum_rating' => 8]])->assertOk();
        $this->get('/media')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('playlists.0.file_ids', fn ($ids) => collect($ids)->sort()->values()->all() === [$first, $second])->etc());
    }

    public function test_private_resources_and_invalid_ratings_are_rejected(): void
    {
        $owner = User::factory()->create();
        $directory = Directory::factory()->create(['user_id' => $owner->id]);
        $track = File::factory()->uploaded()->create(['user_id' => $owner->id, 'file_type' => 'audio']);
        $id = $this->actingAs($owner)->postJson('/media/playlists', ['name' => 'Mine', 'file_ids' => [$track->id]])->assertCreated()->json('id');
        $this->patchJson('/media/tracks/'.$track->id, ['rating' => 11])->assertUnprocessable();
        $this->actingAs(User::factory()->create());
        $this->patchJson('/media/library', ['directory_id' => $directory->id])->assertUnprocessable();
        $this->postJson('/media/playlists', ['name' => 'Other', 'file_ids' => [$track->id]])->assertUnprocessable();
        $this->patchJson('/media/playlists/'.$id, ['name' => 'Other'])->assertNotFound();
        $this->patchJson('/media/playlists/'.$id, ['ordered_file_ids' => [$track->id]])->assertNotFound();
        $this->deleteJson('/media/playlists/'.$id)->assertNotFound();
        $this->assertDatabaseHas('media_playlists', ['id' => $id, 'user_id' => $owner->id]);
        $this->patchJson('/media/tracks/'.$track->id, ['favorite' => true])->assertForbidden();
    }
}
