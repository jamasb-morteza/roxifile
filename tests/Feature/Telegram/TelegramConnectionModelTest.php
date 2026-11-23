<?php

namespace Tests\Feature\Telegram;

use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Enums\ChatType;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramConnectionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_user_and_account(): void
    {
        $connection = TelegramConnection::factory()->create();

        $this->assertInstanceOf(User::class, $connection->user);
        $this->assertInstanceOf(TelegramAccount::class, $connection->account);
    }

    public function test_connected_state_has_chat_identifiers(): void
    {
        $connection = TelegramConnection::factory()->connected()->create();

        $this->assertSame(TelegramConnectionStatus::Connected, $connection->status);
        $this->assertNotNull($connection->chat_id);
        $this->assertSame(ChatType::Private, $connection->chat_type);
        $this->assertNotNull($connection->last_verified_at);
    }

    public function test_new_connections_start_pending_without_chat(): void
    {
        $connection = TelegramConnection::factory()->create();

        $this->assertSame(TelegramConnectionStatus::Pending, $connection->status);
        $this->assertNull($connection->chat_id);
    }

    public function test_group_state_uses_negative_chat_id(): void
    {
        $connection = TelegramConnection::factory()->group()->create();

        $this->assertSame(ChatType::Supergroup, $connection->chat_type);
        $this->assertLessThan(0, $connection->chat_id);
    }

    public function test_user_has_many_connections(): void
    {
        $user = User::factory()->create();
        $connection = TelegramConnection::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($user->telegramConnections->contains($connection));
    }

    public function test_different_telegram_identities_cannot_create_connected_invites_to_the_same_chat(): void
    {
        $connection = TelegramConnection::factory()->invite(12345, 'alice')->connected()->create([
            'chat_id' => -1000000123456, 'chat_type' => 'channel',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        TelegramConnection::factory()->invite(67890, 'other_user')->connected()->create([
            'telegram_account_id' => $connection->telegram_account_id, 'chat_id' => $connection->chat_id, 'chat_type' => 'channel',
        ]);
    }
}
