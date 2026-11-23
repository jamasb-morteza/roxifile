<?php

namespace Database\Factories;

use App\Domains\Files\Models\Directory;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Directory>
 */
class DirectoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'user_id' => User::factory(),
            'parent_id' => null,
            'name' => $name,
            'path' => $name,
        ];
    }

    /**
     * Always materialize the path from the final name (and parent path),
     * so create(['name' => ...]) overrides cannot leave a stale path.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Directory $directory): void {
            $directory->path = $directory->parent_id !== null
                ? trim($directory->parent->path.'/'.$directory->name, '/')
                : $directory->name;
            $directory->save();
        });
    }

    /**
     * Create the directory inside the given parent with a correct path.
     */
    public function forParent(Directory $parent): static
    {
        return $this
            ->state(fn (): array => [
                'user_id' => $parent->user_id,
                'parent_id' => $parent->id,
            ])
            ->afterCreating(function (Directory $directory) use ($parent): void {
                $directory->path = trim($parent->path.'/'.$directory->name, '/');
                $directory->save();
            });
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user->id,
        ]);
    }
}
