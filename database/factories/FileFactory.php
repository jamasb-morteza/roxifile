<?php

namespace Database\Factories;

use App\Domains\Files\Enums\FileStatus;
use App\Domains\Files\Enums\FileType;
use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<File>
 */
class FileFactory extends Factory
{
    /**
     * Realistic mime/extension pairs; file_type is always derived from the
     * MIME type, mirroring production behavior.
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private const SAMPLES = [
        ['invoice.pdf', 'application/pdf', 'pdf'],
        ['photo.jpg', 'image/jpeg', 'jpg'],
        ['clip.mp4', 'video/mp4', 'mp4'],
        ['song.mp3', 'audio/mpeg', 'mp3'],
        ['backup.zip', 'application/zip', 'zip'],
        ['notes.txt', 'text/plain', 'txt'],
        ['budget.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx'],
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        [$name, $mime, $extension] = fake()->randomElement(self::SAMPLES);

        return [
            'user_id' => User::factory(),
            'directory_id' => null,
            'telegram_account_id' => null,
            'telegram_connection_id' => null,
            'name' => $name,
            'original_name' => $name,
            'extension' => $extension,
            'mime_type' => $mime,
            'file_type' => FileType::fromMime($mime),
            'size' => fake()->numberBetween(1024, 10_000_000),
            'checksum' => null,
            'description' => null,
            'status' => FileStatus::Pending,
        ];
    }

    /**
     * A file physically stored on Telegram.
     */
    public function uploaded(): static
    {
        return $this->state(fn (): array => [
            'status' => FileStatus::Uploaded,
            'telegram_account_id' => TelegramAccount::factory(),
            'telegram_connection_id' => TelegramConnection::factory()->connected(),
            'telegram_chat_id' => fake()->numberBetween(1_000_000, 9_999_999_999),
            'telegram_message_id' => fake()->numberBetween(1, 999_999),
            'telegram_media_id' => fake()->uuid(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => FileStatus::Failed,
        ]);
    }

    public function trashed(): static
    {
        return $this->state(fn (): array => [
            'status' => FileStatus::Deleted,
            'deleted_at' => now(),
        ]);
    }

    public function inDirectory(Directory $directory): static
    {
        return $this->state(fn (): array => [
            'user_id' => $directory->user_id,
            'directory_id' => $directory->id,
        ]);
    }
}
