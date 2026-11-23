<?php

namespace Tests\Feature\Telegram;

use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Enums\TelegramAccountStatus;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramAccountModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_a_user(): void
    {
        $account = TelegramAccount::factory()->forUser($user = User::factory()->create())->create();

        $this->assertTrue($user->telegramAccounts->contains($account));
    }

    public function test_system_accounts_have_no_user(): void
    {
        $account = TelegramAccount::factory()->system()->create();

        $this->assertNull($account->user_id);
        $this->assertTrue($account->is_system);
        $this->assertSame(TelegramMethod::Roxi, $account->method);
        $this->assertSame(TelegramAccountStatus::Connected, $account->status);
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $account = TelegramAccount::factory()->bot()->create(['credentials' => '123456:SECRET-TOKEN']);

        $account->refresh();

        $this->assertSame('123456:SECRET-TOKEN', $account->credentials);
        $this->assertNotSame('123456:SECRET-TOKEN', $account->getRawOriginal('credentials'));
        $this->assertStringNotContainsString('SECRET-TOKEN', (string) $account->getRawOriginal('credentials'));
    }

    public function test_has_many_connections(): void
    {
        if (! class_exists(TelegramConnection::class)) {
            $this->markTestSkipped('Needs the TelegramConnection model (added in Task 3).');
        }

        $account = TelegramAccount::factory()->create();

        $this->assertInstanceOf(HasMany::class, $account->connections());
    }
}
