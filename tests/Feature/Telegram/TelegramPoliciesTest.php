<?php

namespace Tests\Feature\Telegram;

use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramPoliciesTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_only_view_their_own_accounts(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $account = TelegramAccount::factory()->forUser($owner)->create();

        $this->assertTrue($owner->can('view', $account));
        $this->assertFalse($other->can('view', $account));
    }

    public function test_system_accounts_cannot_be_updated_or_deleted_by_anyone(): void
    {
        $user = User::factory()->create();
        $systemAccount = TelegramAccount::factory()->system()->create();

        $this->assertFalse($user->can('update', $systemAccount));
        $this->assertFalse($user->can('delete', $systemAccount));
    }

    public function test_any_authenticated_user_may_create_accounts(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->can('create', TelegramAccount::class));
    }

    public function test_users_can_only_view_their_own_connections(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $connection = TelegramConnection::factory()->create(['user_id' => $owner->id]);

        $this->assertTrue($owner->can('view', $connection));
        $this->assertFalse($other->can('view', $connection));
        $this->assertTrue($owner->can('manage', $connection));
        $this->assertFalse($other->can('manage', $connection));
        $this->assertTrue($owner->can('delete', $connection));
        $this->assertFalse($other->can('delete', $connection));
    }
}
