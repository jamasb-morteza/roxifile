<?php

namespace App\Domains\Files\Services;

use App\Domains\Files\Actions\QueueTelegramMetadata;
use App\Domains\Files\Data\QueueTelegramMetadataData;
use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;
use Illuminate\Support\Facades\DB;

/**
 * Maintains the materialized virtual path on directories.
 *
 * The full virtual path (e.g. "Documents/Invoices/2026") is stored on every
 * row so breadcrumbs, search, and subtree moves never need recursive queries.
 * Top-level directories (parent_id = null) use their own name as the path.
 */
class DirectoryPathService
{
    /**
     * Build the full path for a directory by walking its ancestors.
     */
    public static function build(Directory $directory): string
    {
        $parts = [];
        $current = $directory;

        while ($current->parent_id !== null) {
            $parts[] = $current->name;
            $current = $current->parent()->firstOrFail();
        }

        $parts[] = $current->name;

        return implode('/', array_reverse($parts));
    }

    /**
     * Rename a directory and rewrite all descendant paths.
     */
    public static function rename(Directory $directory, string $newName): void
    {
        $parentPath = $directory->parent_id !== null
            ? dirname($directory->path)
            : '';

        $newPath = $parentPath === '' || $parentPath === '.'
            ? $newName
            : $parentPath.'/'.$newName;

        $directory->name = $newName;
        self::setPath($directory, $newPath);
    }

    /**
     * Move a directory under a new parent (null = top level) and rewrite
     * all descendant paths.
     */
    public static function move(Directory $directory, ?Directory $newParent): void
    {
        $newPath = $newParent === null
            ? $directory->name
            : trim($newParent->path.'/'.$directory->name, '/');

        $directory->parent_id = $newParent?->id;
        self::setPath($directory, $newPath);
    }

    /**
     * Save the directory with its new path and rewrite descendants with a
     * single prefix UPDATE.
     */
    public static function setPath(Directory $directory, string $newPath): void
    {
        $oldPath = $directory->path;

        DB::transaction(function () use ($directory, $newPath, $oldPath): void {
            $directory->path = $newPath;
            $directory->save();

            if ($oldPath === '' || $oldPath === $newPath) {
                return;
            }

            // Descendant path = new path + "/" + everything after old path's
            // trailing slash. SUBSTR is 1-based; strlen(oldPath) + 2 skips
            // "oldPath/" itself.
            DB::update(
                'UPDATE directories SET path = ? || SUBSTR(path, ?) '
                .'WHERE user_id = ? AND SUBSTR(path, 1, ?) = ?',
                [
                    $newPath.'/',
                    mb_strlen($oldPath) + 2,
                    $directory->user_id,
                    mb_strlen($oldPath) + 1,
                    $oldPath.'/',
                ],
            );
            File::query()->where('user_id', $directory->user_id)
                ->whereIn('directory_id', Directory::query()->where('user_id', $directory->user_id)
                    ->where(fn ($query) => $query->whereKey($directory->id)->orWhereRaw('SUBSTR(path, 1, ?) = ?', [mb_strlen($newPath) + 1, $newPath.'/']))->select('id'))
                ->each(fn (File $file) => app(QueueTelegramMetadata::class)->handle(new QueueTelegramMetadataData($file->id, $file->status, $file->telegram_message_id)));
        });
    }
}
