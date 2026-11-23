<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Enums\ChatType;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramConnection>
 */
class TelegramConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'telegram_account_id' => TelegramAccount::factory(),
            'method' => TelegramMethod::Roxi,
            'name' => fake()->words(2, true),
            'status' => TelegramConnectionStatus::Pending,
            'metadata' => null,
        ];
    }

    /**
     * A verified, working connection with a resolved chat.
     */
    public function connected(): static
    {
        return $this->state(fn (): array => [
            'status' => TelegramConnectionStatus::Connected,
            'chat_id' => fake()->numberBetween(1_000_000, 9_999_999_999),
            'chat_type' => ChatType::Private,
            'chat_title' => fake()->name(),
            'chat_username' => fake()->userName(),
            'last_verified_at' => now(),
        ]);
    }

    /**
     * A connection backed by a supergroup storage chat.
     */
    public function group(): static
    {
        return $this->state(fn (): array => [
            'status' => TelegramConnectionStatus::Connected,
            'chat_id' => '-'.fake()->numberBetween(1_000_000_000_000, 9_999_999_999_999),
            'chat_type' => ChatType::Supergroup,
            'chat_title' => fake()->words(2, true),
            'last_verified_at' => now(),
        ]);
    }

    /**
     * An invite-method connection that designates a target account.
     */
    public function invite(int $targetUserId, string $identifier): static
    {
        return $this->state(fn (): array => [
            'method' => TelegramMethod::Invite,
            'target_user_id' => $targetUserId,
            'target_identifier' => $identifier,
            'metadata' => ['provisioning' => 'invite'],
        ]);
    }

    public function disconnected(): static
    {
        return $this->state(fn (): array => [
            'status' => TelegramConnectionStatus::Disconnected,
        ]);
    }
}
