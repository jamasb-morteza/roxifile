<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeleteDirectory
{
    public function handle(Directory $directory): void
    {
        DB::transaction(function () use ($directory): void {
            User::query()->whereKey($directory->user_id)->lockForUpdate()->firstOrFail();
            $directory = Directory::query()->lockForUpdate()->findOrFail($directory->id);
            $ids = $this->subtreeIds($directory);
            if (FileOperation::query()->where('user_id', $directory->user_id)->whereIn('operation', ['upload', 'copy'])
                ->whereNotIn('status', ['completed', 'cancelled', 'failed'])->whereHas('file', fn ($query) => $query->whereIn('directory_id', $ids))->exists()) {
                throw new \InvalidArgumentException('Finish or cancel transfers in this folder before moving it to Trash.');
            }
            $attributes = ['deleted_at' => now(), 'deletion_batch' => (string) Str::ulid()];

            Directory::query()->whereIn('id', $ids)->update($attributes);
            File::query()->where('user_id', $directory->user_id)->whereIn('directory_id', $ids)->update($attributes);
        });

        $directory->refresh();
    }

    /** @return list<int> */
    public function subtreeIds(Directory $directory, bool $includeTrashed = false): array
    {
        $ids = [(int) $directory->id];
        $parents = $ids;

        while ($parents !== []) {
            $children = Directory::query()->when($includeTrashed, fn ($query) => $query->withTrashed())
                ->where('user_id', $directory->user_id)->whereIn('parent_id', $parents)
                ->whereNotIn('id', $ids)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
            $ids = array_merge($ids, $children);
            $parents = $children;
        }

        return $ids;
    }
}
