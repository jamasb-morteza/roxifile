<?php

namespace App\Domains\Files\Data;

final readonly class UpdateMediaLibraryData
{
    /** @param array{smart_rules?: array{tag?: string|null, minimum_rating?: int|null, favorite_only?: bool, metadata?: string|null, minimum_plays?: int|null}, directory_id?: int|null, parent_id?: int|null, cover_file_id?: int|null, details?: array{title?: string|null, artist?: string|null, album?: string|null, genre?: string|null, track_number?: int|null, lyrics?: string|null, cover_file_id?: int|null, album_cover_file_id?: int|null}, folder_id?: int|null, name?: string, file_ids?: list<int>, ordered_file_ids?: list<int>, add_file_id?: int, add_file_ids?: list<int>, remove_file_id?: int, favorite?: bool, rating?: int|null, event_id?: string} $changes */
    public function __construct(public int $userId, public array $changes) {}
}
