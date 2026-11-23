<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Data\UpdateMediaLibraryData;
use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Runtime\TelegramDownloadStreamToken;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageMediaLibrary
{
    /** @return array<string, mixed> */
    public function browse(int $userId): array
    {
        $directories = Directory::query()->where('user_id', $userId)->orderBy('path')->get(['id', 'parent_id', 'name', 'path']);
        $directoryId = DB::table('media_libraries')->where('user_id', $userId)->value('directory_id');
        $directoryId = $directoryId === null ? null : (int) $directoryId;
        $directoryIds = [];
        if ($directoryId !== null && $directories->contains('id', $directoryId)) {
            $children = $directories->groupBy('parent_id');
            $directoryIds[] = $directoryId;
            for ($index = 0; $index < count($directoryIds); $index++) {
                foreach ($children->get($directoryIds[$index], collect()) as $child) {
                    $directoryIds[] = $child->id;
                }
            }
        }

        $playlists = DB::table('media_playlists')->where('user_id', $userId)->orderBy('id')->get(['id', 'name', 'file_ids', 'folder_id', 'cover_file_id', 'smart_rules'])
            ->map(fn (object $playlist): array => [
                'id' => (int) $playlist->id,
                'name' => $playlist->name,
                'cover_file_id' => $playlist->cover_file_id === null ? null : (int) $playlist->cover_file_id,
                'folder_id' => $playlist->folder_id === null ? null : (int) $playlist->folder_id,
                'file_ids' => json_decode($playlist->file_ids, true, flags: JSON_THROW_ON_ERROR),
                'smart_rules' => $playlist->smart_rules === null ? null : json_decode($playlist->smart_rules, true, flags: JSON_THROW_ON_ERROR),
            ]);
        $folders = $this->folders($userId)->orderBy('id')->get(['id', 'name', 'parent_id'])->map(fn (object $folder): array => [
            'id' => (int) $folder->id,
            'name' => $folder->name,
            'parent_id' => $folder->parent_id === null ? null : (int) $folder->parent_id,
        ]);
        $preferences = DB::table('media_track_preferences')->where('user_id', $userId)->get()->keyBy('file_id');
        $history = $this->playbackHistoryQuery($userId);
        $recent = (clone $history)->orderByDesc('last_playback_id')->limit(10)->get();
        $most = $history->orderByDesc('play_count')->orderByDesc('last_playback_id')->limit(10)->get();
        $historyIds = $recent->pluck('file_id')->merge($most->pluck('file_id'))->unique();
        $hasSmartPlaylists = $playlists->contains(fn (array $playlist): bool => $playlist['smart_rules'] !== null);
        $tracks = File::query()->where('user_id', $userId)->where('status', 'uploaded')->where(fn ($query) => $query->whereIn('file_type', ['audio', 'video'])->orWhereNotNull('encryption_header'))
            ->when(! $hasSmartPlaylists, fn ($scope) => $scope->where(fn ($query) => $query->whereIntegerInRaw('directory_id', $directoryIds)->orWhereIntegerInRaw('id', $playlists->pluck('file_ids')->flatten()->unique()->values())->orWhereIntegerInRaw('id', $preferences->where('favorite', true)->keys())->orWhereIntegerInRaw('id', $historyIds)))
            ->with(['tags:id,name', 'connection:id,encryption_mode,method'])->orderBy('name')->orderBy('id')->get()
            ->map(fn (File $file): array => [
                'id' => $file->id,
                'name' => $file->name,
                'mime_type' => $file->metadata['plaintext_mime'] ?? $file->mime_type,
                'protected' => $file->connection?->encryption_mode === 'contents_and_metadata',
                'details' => $file->connection?->encryption_mode === 'contents_and_metadata' ? null : ($file->metadata['media_details'] ?? null),
                'encrypted' => $file->encryption_header !== null,
                'streamable' => $file->encryption_header === null
                    && $file->connection?->encryption_mode === 'none'
                    && in_array($file->connection?->method, [TelegramMethod::Roxi, TelegramMethod::Invite], true)
                    && $file->size > 0
                    && $file->size <= TelegramDownloadStreamToken::MAX_FILE_BYTES,
                'discovered' => in_array($file->directory_id, $directoryIds, true),
                'tags' => $file->tags->map(fn ($tag): array => ['id' => $tag->id, 'name' => $tag->name]),
                'favorite' => (bool) ($preferences->get($file->id)->favorite ?? false),
                'rating' => isset($preferences[$file->id]->rating) ? (int) $preferences[$file->id]->rating : null,
            ]);

        if ($hasSmartPlaylists) {
            $plays = $playlists->contains(fn (array $playlist): bool => isset($playlist['smart_rules']['minimum_plays']))
                ? $this->playbackHistoryQuery($userId)->pluck('play_count', 'file_id')
                : collect();
            $playlists = $playlists->map(function (array $playlist) use ($tracks, $plays): array {
                $rules = $playlist['smart_rules'];
                if ($rules !== null) {
                    $playlist['file_ids'] = $tracks
                        ->filter(fn (array $track): bool => $this->matchesSmartPlaylist($track, $rules, (int) ($plays[$track['id']] ?? 0)))
                        ->pluck('id')->values()->all();
                }

                return $playlist;
            });
            $listedIds = $playlists->pluck('file_ids')->flatten()->merge($historyIds)->flip();
            $tracks = $tracks->filter(fn (array $track): bool => $track['discovered'] || $track['favorite'] || $listedIds->has($track['id']))->values();
        }

        $artwork = File::query()->where('user_id', $userId)->where('status', 'uploaded')->where('file_type', 'image')
            ->whereNull('encryption_header')->whereIn('mime_type', File::ARTWORK_MIME_TYPES)
            ->orderBy('name')->get(['id', 'name']);

        return [
            'artwork' => $artwork,
            'directoryId' => $directoryId,
            'directories' => $directories,
            'playlists' => $playlists,
            'folders' => $folders,
            'tracks' => $tracks,
            'history' => [
                'recent' => $recent->pluck('file_id')->map(intval(...)),
                'most' => $most->pluck('file_id')->map(intval(...)),
                'counts' => $most->concat($recent)->pluck('play_count', 'file_id')->map(intval(...)),
            ],
        ];
    }

    public function playbackHistoryQuery(int $userId): Builder
    {
        return DB::table('media_playback_events')
            ->where('media_playback_events.user_id', $userId)
            ->whereIn('file_id', File::query()->select('id')->where('user_id', $userId)->where('status', 'uploaded')
                ->where(fn ($query) => $query->whereIn('file_type', ['audio', 'video'])->orWhereNotNull('encryption_header')))
            ->selectRaw('file_id, COUNT(*) as play_count, MAX(id) as last_playback_id, MAX(played_at) as last_played_at')
            ->groupBy('file_id');
    }

    public function recordPlayback(UpdateMediaLibraryData $data, File $file): void
    {
        abort_unless((in_array($file->file_type->value, ['audio', 'video'], true) || $file->encryption_header !== null) && $file->status->value === 'uploaded', 404);

        $eventId = $data->changes['event_id'] ?? null;
        abort_if($eventId === null, 422);

        DB::table('media_playback_events')->upsert([
            [
                'user_id' => $data->userId,
                'file_id' => $file->id,
                'event_id' => strtolower($eventId),
                'played_at' => now(),
            ],
        ], ['user_id', 'event_id'], ['event_id']);
    }

    /**
     * @param  array{details: array<string, mixed>|null, rating: int|null, favorite: bool, tags: Collection<int, array{id: mixed, name: mixed}>}  $track
     * @param  array{tag?: string|null, minimum_rating?: int|null, favorite_only?: bool, metadata?: string|null, minimum_plays?: int|null}  $rules
     */
    private function matchesSmartPlaylist(array $track, array $rules, int $plays): bool
    {
        $metadata = $rules['metadata'] ?? null;
        $details = $track['details'] ?? [];
        $searchable = implode(' ', array_intersect_key($details, array_flip(['title', 'artist', 'album', 'genre'])));

        return (($rules['tag'] ?? null) === null || $track['tags']->contains('name', $rules['tag']))
            && ($track['rating'] ?? 0) >= ($rules['minimum_rating'] ?? 0)
            && (! ($rules['favorite_only'] ?? false) || $track['favorite'])
            && ($metadata === null || str_contains(mb_strtolower($searchable), mb_strtolower($metadata)))
            && $plays >= ($rules['minimum_plays'] ?? 0);
    }

    public function setDirectory(UpdateMediaLibraryData $data): void
    {
        DB::table('media_libraries')->upsert([
            ['user_id' => $data->userId, 'directory_id' => $data->changes['directory_id'] ?? null],
        ], ['user_id'], ['directory_id']);
    }

    public function createPlaylist(UpdateMediaLibraryData $data): int
    {
        return DB::transaction(function () use ($data): int {
            $this->lockFolderChanges($data->userId);
            $folderId = $this->playlistFolderId($data);

            return (int) DB::table('media_playlists')->insertGetId([
                'user_id' => $data->userId,
                'name' => $data->changes['name'] ?? '',
                'folder_id' => $folderId,
                'smart_rules' => isset($data->changes['smart_rules']) ? json_encode($data->changes['smart_rules'], JSON_THROW_ON_ERROR) : null,
                'cover_file_id' => $data->changes['cover_file_id'] ?? null,
                'file_ids' => json_encode(array_map(intval(...), $data->changes['file_ids'] ?? []), JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function updatePlaylist(UpdateMediaLibraryData $data, int $id): void
    {
        DB::transaction(function () use ($data, $id): void {
            $movesFolder = array_key_exists('folder_id', $data->changes);
            if ($movesFolder) {
                $this->lockFolderChanges($data->userId);
            }

            $query = DB::table('media_playlists')->where('user_id', $data->userId)->where('id', $id);
            $playlist = $query->lockForUpdate()->first();
            abort_if($playlist === null, 404);
            $changes = $data->changes;
            if ($playlist->smart_rules !== null && array_intersect_key($changes, array_flip(['file_ids', 'ordered_file_ids', 'add_file_ids', 'add_file_id', 'remove_file_id'])) !== []) {
                throw ValidationException::withMessages(['file_ids' => 'Edit the smart playlist rules to change its tracks.']);
            }

            $savedIds = json_decode($playlist->file_ids, true, flags: JSON_THROW_ON_ERROR);
            $ids = array_map(intval(...), $changes['file_ids'] ?? $savedIds);
            if (isset($changes['ordered_file_ids'])) {
                $ids = array_map(intval(...), $changes['ordered_file_ids']);
                if (count($ids) !== count($savedIds) || array_diff($savedIds, $ids) !== []) {
                    throw ValidationException::withMessages(['ordered_file_ids' => 'Playlist tracks have changed. Reload the playlist and try again.']);
                }
            }
            if (isset($changes['add_file_id'])) {
                $ids[] = (int) $changes['add_file_id'];
            }
            if (isset($changes['add_file_ids'])) {
                $ids = [...$ids, ...array_map(intval(...), $changes['add_file_ids'])];
            }
            if (isset($changes['remove_file_id'])) {
                $ids = array_diff($ids, [(int) $changes['remove_file_id']]);
            }
            $ids = array_values(array_unique($ids));
            if (count($ids) > 1000) {
                throw ValidationException::withMessages(['file_ids' => 'A playlist can hold up to 1,000 files.']);
            }
            $query->update([
                'name' => $changes['name'] ?? $playlist->name,
                ...(isset($changes['smart_rules']) ? ['smart_rules' => json_encode($changes['smart_rules'], JSON_THROW_ON_ERROR)] : []),
                ...(array_key_exists('cover_file_id', $changes) ? ['cover_file_id' => $changes['cover_file_id']] : []),
                ...($movesFolder ? ['folder_id' => $this->playlistFolderId($data)] : []),
                'file_ids' => json_encode($ids, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        });
    }

    public function deletePlaylist(UpdateMediaLibraryData $data, int $playlistId): void
    {
        $deleted = DB::table('media_playlists')->where('user_id', $data->userId)->where('id', $playlistId)->delete();
        abort_if($deleted === 0, 404);
    }

    public function saveFolder(UpdateMediaLibraryData $data, ?int $folderId = null): int
    {
        return DB::transaction(function () use ($data, $folderId): int {
            $this->lockFolderChanges($data->userId);
            $folders = $this->folders($data->userId);
            $folder = $folderId === null ? null : (clone $folders)->where('id', $folderId)->first();
            abort_if($folderId !== null && $folder === null, 404);

            $changes = $data->changes;
            $attributes = ['name' => $changes['name'] ?? $folder->name ?? ''];
            if (array_key_exists('parent_id', $changes)) {
                $parentId = $changes['parent_id'] === null ? null : (int) $changes['parent_id'];
                $parents = $folders->pluck('parent_id', 'id');
                $ancestor = $parentId;
                while ($ancestor !== null) {
                    if ($ancestor === $folderId || ! $parents->has($ancestor)) {
                        throw ValidationException::withMessages(['parent_id' => 'Choose a folder outside this folder and its subfolders.']);
                    }
                    $parent = $parents->get($ancestor);
                    $ancestor = $parent === null ? null : (int) $parent;
                }
                $attributes['parent_id'] = $parentId;
            }

            if ($folderId === null) {
                return (int) $folders->insertGetId(['user_id' => $data->userId, ...$attributes]);
            }

            $folders->where('id', $folderId)->update($attributes);

            return $folderId;
        });
    }

    public function deleteFolder(UpdateMediaLibraryData $data, int $folderId): void
    {
        DB::transaction(function () use ($data, $folderId): void {
            $this->lockFolderChanges($data->userId);
            $deleted = $this->folders($data->userId)->where('id', $folderId)->delete();
            abort_if($deleted === 0, 404);
        });
    }

    private function folders(int $userId): Builder
    {
        return DB::table('media_playlist_folders')->where('user_id', $userId);
    }

    private function lockFolderChanges(int $userId): void
    {
        DB::table('users')->where('id', $userId)->lockForUpdate()->first();
    }

    private function playlistFolderId(UpdateMediaLibraryData $data): ?int
    {
        $folderId = $data->changes['folder_id'] ?? null;
        if ($folderId !== null && ! $this->folders($data->userId)->where('id', $folderId)->exists()) {
            throw ValidationException::withMessages(['folder_id' => 'Choose one of your playlist folders.']);
        }

        return $folderId === null ? null : (int) $folderId;
    }

    public function updateTrack(UpdateMediaLibraryData $data, File $file): void
    {
        abort_unless((in_array($file->file_type->value, ['audio', 'video'], true) || $file->encryption_header !== null) && $file->status->value === 'uploaded', 404);
        DB::transaction(function () use ($data, $file): void {
            $changes = $data->changes;
            if (array_key_exists('details', $changes)) {
                $file = File::query()->lockForUpdate()->findOrFail($file->id);
                if ($file->connection?->encryption_mode === 'contents_and_metadata') {
                    throw ValidationException::withMessages(['details' => 'Unlock this track to save its metadata in the vault.']);
                }

                $metadata = $file->metadata ?? [];
                $metadata['media_details'] = array_replace($metadata['media_details'] ?? [], $changes['details']);
                $file->update(['metadata' => $metadata]);
                unset($changes['details']);
            }

            if ($changes !== []) {
                DB::table('media_track_preferences')->upsert([
                    ['user_id' => $data->userId, 'file_id' => $file->id, ...$changes],
                ], ['user_id', 'file_id'], array_keys($changes));
            }
        });
    }
}
