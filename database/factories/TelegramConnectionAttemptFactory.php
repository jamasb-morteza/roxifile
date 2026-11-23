<?php

namespace Database\Factories;

use App\Domains\Telegram\Enums\ConnectionAttemptResult;
use App\Domains\Telegram\Enums\ConnectionAttemptType;
use App\Domains\Telegram\Models\TelegramConnectionAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramConnectionAttempt>
 */
class TelegramConnectionAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'telegram_connection_id' => null,
            'telegram_account_id' => null,
            'type' => ConnectionAttemptType::Provision,
            'result' => ConnectionAttemptResult::Success,
            'error' => null,
            'metadata' => null,
            'created_at' => now(),
        ];
    }

    public function failure(string $error): static
    {
        return $this->state(fn (): array => [
            'result' => ConnectionAttemptResult::Failure,
            'error' => $error,
        ]);
    }
}
