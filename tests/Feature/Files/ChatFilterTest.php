<?php

namespace Tests\Feature\Files;

use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ChatFilterTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_files_can_be_filtered_by_chat_when_one_telegram_identity_has_multiple_chats(bool $trash): void
    {
        $user = User::factory()->create();
        $channel = TelegramConnection::factory()->invite(12345, 'alice')->connected()->create([
            'user_id' => $user->id, 'name' => 'Archive A', 'chat_id' => -1000000123456, 'chat_type' => 'channel', 'chat_title' => 'Channel archive',
        ]);
        $group = TelegramConnection::factory()->invite(12345, 'alice')->connected()->create([
            'user_id' => $user->id, 'telegram_account_id' => $channel->telegram_account_id,
            'name' => 'Archive B', 'chat_id' => -123456, 'chat_type' => 'group', 'chat_title' => 'Group archive', 'status' => 'disabled',
        ]);
        $first = File::factory()->uploaded()->create([
            'user_id' => $user->id, 'telegram_account_id' => $channel->telegram_account_id,
            'telegram_connection_id' => $channel->id, 'telegram_chat_id' => $channel->chat_id,
        ]);
        $second = File::factory()->uploaded()->create([
            'user_id' => $user->id, 'telegram_account_id' => $group->telegram_account_id,
            'telegram_connection_id' => $group->id, 'telegram_chat_id' => $group->chat_id,
        ]);
        if ($trash) {
            $first->delete();
            $second->delete();
        }

        $this->actingAs($user)->get(route('files.index', ['telegram_connection_id' => $group->id, 'trash' => $trash ? 1 : 0, 'scope' => 'all']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('files.data', 1)
                ->where('files.data.0.id', $second->id)
                ->where('filters.telegram_connection_id', $group->id)
                ->where('filter_connections.0.chat_title', 'Channel archive')
                ->where('filter_connections.1.chat_title', 'Group archive'));

        $this->get(route('files.index', ['trash' => $trash ? 1 : 0, 'scope' => 'all']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('files.data', 2)->where('filters.telegram_connection_id', null));
    }

    public function test_root_listing_combines_files_from_all_chats_and_nested_directories(): void
    {
        $user = User::factory()->create();
        $firstChat = TelegramConnection::factory()->connected()->create(['user_id' => $user->id, 'name' => 'Chat One', 'chat_title' => 'Chat One']);
        $secondChat = TelegramConnection::factory()->connected()->create(['user_id' => $user->id, 'name' => 'Chat Two']);
        $directory = Directory::factory()->create(['user_id' => $user->id]);
        $firstFile = File::factory()->uploaded()->create([
            'user_id' => $user->id, 'telegram_connection_id' => $firstChat->id, 'directory_id' => $directory->id,
        ]);
        $secondFile = File::factory()->uploaded()->create([
            'user_id' => $user->id, 'telegram_connection_id' => $secondChat->id, 'directory_id' => null,
        ]);

        $this->actingAs($user)->get(route('files.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('files.data', 2)
                ->where('filters.telegram_connection_id', null)
                ->where('storageChats.0.name', 'Chat One')
                ->where('storageChats.1.name', 'Chat Two'));

        $this->get(route('files.index', ['telegram_connection_id' => $firstChat->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('files.data', 1)
                ->where('files.data.0.id', $firstFile->id)
                ->where('files.data.0.telegram.connection', 'Chat One'));

        $this->get(route('files.index', ['telegram_connection_id' => $secondChat->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('files.data', 1)
                ->where('files.data.0.id', $secondFile->id));

        $this->get(route('files.index', ['telegram_connection_id' => $secondChat->id, 'directory_id' => $directory->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('files.data', 0));
    }

    public function test_sidebar_chat_photos_use_public_chat_username_or_a_private_created_chat_icon(): void
    {
        $user = User::factory()->create();
        TelegramConnection::factory()->connected()->create([
            'user_id' => $user->id, 'name' => 'Public Chat', 'chat_username' => 'public_chat_name',
        ]);
        TelegramConnection::factory()->connected()->create([
            'user_id' => $user->id, 'name' => 'Private Chat', 'chat_username' => null,
            'metadata' => ['created_chat_photo_set' => true],
        ]);

        $this->actingAs($user)->get(route('files.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('storageChats.0.avatar_url', '/icon.png')
                ->where('storageChats.1.avatar_url', 'https://t.me/i/userpic/320/public_chat_name.jpg'));
    }

    public function test_filter_options_and_results_never_expose_another_users_chat(): void
    {
        $user = User::factory()->create();
        $foreign = TelegramConnection::factory()->connected()->create(['chat_title' => 'Private foreign chat']);
        File::factory()->uploaded()->create(['user_id' => $foreign->user_id, 'telegram_connection_id' => $foreign->id]);

        $this->actingAs($user)->get(route('files.index', ['telegram_connection_id' => $foreign->id, 'scope' => 'all']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('files.data', 0)->has('filter_connections', 0)->has('storageChats', 0))
            ->assertDontSee('Private foreign chat');
    }
}
