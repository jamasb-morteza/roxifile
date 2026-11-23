<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Enums\TelegramAccountStatus;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Models\TelegramAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramAccount>
 */
class TelegramAccountFactory extends Factory
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
            'name' => fake()->words(2, true),
            'method' => TelegramMethod::Personal,
            'is_system' => false,
            'status' => TelegramAccountStatus::Disconnected,
            'credentials' => null,
            'metadata' => null,
        ];
    }

    /**
     * The system-owned Roxi shared account.
     */
    public function system(): static
    {
        return $this->state(fn (): array => [
            'user_id' => null,
            'name' => 'Roxi',
            'method' => TelegramMethod::Roxi,
            'is_system' => true,
            'status' => TelegramAccountStatus::Connected,
        ]);
    }

    /**
     * A user-provided bot account.
     */
    public function bot(): static
    {
        return $this->state(fn (): array => [
            'method' => TelegramMethod::Bot,
            'status' => TelegramAccountStatus::Connected,
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user->id,
        ]);
    }
}
