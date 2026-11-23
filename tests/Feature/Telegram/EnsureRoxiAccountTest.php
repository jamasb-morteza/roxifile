<?php

namespace Tests\Feature\Telegram;

use App\Domains\Telegram\Actions\EnsureRoxiAccount;
use App\Domains\Telegram\Enums\TelegramAccountStatus;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Models\TelegramAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureRoxiAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_the_system_roxi_account_once(): void
    {
        $account = app(EnsureRoxiAccount::class)();

        $this->assertTrue($account->is_system);
        $this->assertSame(TelegramMethod::Roxi, $account->method);
        $this->assertSame(TelegramAccountStatus::Disconnected, $account->status);
        $this->assertNull($account->user_id);
        $this->assertSame('Roxi', $account->name);
    }

    public function test_is_idempotent(): void
    {
        $first = app(EnsureRoxiAccount::class)();
        $second = app(EnsureRoxiAccount::class)();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, TelegramAccount::query()->where('is_system', true)->count());
    }
}
