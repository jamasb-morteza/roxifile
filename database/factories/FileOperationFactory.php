<?php

namespace Database\Factories;

use App\Domains\Files\Enums\FileOperationStatus;
use App\Domains\Files\Enums\FileOperationType;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FileOperation>
 */
class FileOperationFactory extends Factory
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
            'file_id' => File::factory(),
            'operation' => FileOperationType::Upload,
            'status' => FileOperationStatus::Pending,
            'progress' => 0,
            'attempts' => 0,
            'error' => null,
            'metadata' => null,
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => FileOperationStatus::Completed,
            'progress' => 100,
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => FileOperationStatus::Failed,
            'error' => 'telegram.permanent_failure',
        ]);
    }

    public function uploading(): static
    {
        return $this->state(fn (): array => [
            'status' => FileOperationStatus::Uploading,
            'progress' => fake()->numberBetween(1, 99),
            'attempts' => 1,
        ]);
    }
}
