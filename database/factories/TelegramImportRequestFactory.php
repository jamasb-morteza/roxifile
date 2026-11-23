<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Models\TelegramImportRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TelegramImportRequest> */
class TelegramImportRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'telegram_account_id' => TelegramAccount::factory()->system(),
            'telegram_connection_id' => TelegramConnection::factory()->connected(),
            'chat_id' => -12345,
            'source_message_id' => fake()->unique()->numberBetween(1, 1000000),
            'sender_id' => 42,
            'name' => 'report.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'random_id' => random_int(1, PHP_INT_MAX),
            'marker' => 'ROXIFILE-IMPORT-'.Str::uuid(),
            'expires_at' => now()->addDay(),
        ];
    }
}
