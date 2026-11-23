<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Models\File;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Paginated, filterable file listing (spec §19, §45).
 *
 * All ids passed in must already be owner-validated by the caller. Sorting is
 * whitelisted; search keeps case-insensitive substring matching across
 * supported databases and adds PostgreSQL trigram matching when available.
 */
class SearchFiles
{
    public const SORTS = ['name', 'size', 'created_at', 'updated_at', 'file_type'];

    /**
     * @param  array{
     *     q?: ?string,
     *     file_type?: ?string,
     *     mime_type?: ?string,
     *     tag_ids?: ?list<int>,
     *     telegram_account_id?: ?int,
     *     telegram_connection_id?: ?int,
     *     created_after?: ?string,
     *     created_before?: ?string,
     *     directory_id?: ?int,
     *     sort?: ?string,
     *     direction?: ?string,
     *     per_page?: ?int,
     *     trash?: bool,
     *     global?: bool,
     * }  $filters
     * @return LengthAwarePaginator<int, File>
     */
    public function handle(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = File::query()
            ->with(['account', 'connection.account', 'tags', 'directory' => fn ($query) => $query->withTrashed()])
            ->where('files.user_id', $user->id);

        if ($filters['trash'] ?? false) {
            $query->onlyTrashed();
        }

        if (! empty($filters['directory_id']) && ! ($filters['global'] ?? false)) {
            $query->where('files.directory_id', $filters['directory_id']);
        } elseif (! ($filters['trash'] ?? false) && ! ($filters['global'] ?? false)) {
            $query->whereNull('files.directory_id');
        }

        if (($q = trim($filters['q'] ?? '')) !== '') {
            $operator = $this->likeOperator();
            $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
            $trigramSearchEnabled = $this->trigramSearchEnabled();

            $query->whereIn('files.id', $this->matchingFileIds($user, $operator, $needle, $q, $trigramSearchEnabled));

            $this->orderByRelevance($query, $operator, $needle, $q, $trigramSearchEnabled);
        }

        if (! empty($filters['file_type'])) {
            $query->where('files.file_type', $filters['file_type']);
        }

        if (! empty($filters['mime_type'])) {
            $query->where('files.mime_type', $filters['mime_type']);
        }

        if (! empty($filters['tag_ids'])) {
            $query->whereHas('tags', fn ($t) => $t->whereIn('tags.id', $filters['tag_ids']));
        }

        if (! empty($filters['telegram_account_id'])) {
            $query->where('files.telegram_account_id', $filters['telegram_account_id']);
        }

        if (! empty($filters['telegram_connection_id'])) {
            $query->where('files.telegram_connection_id', $filters['telegram_connection_id']);
        }

        if (! empty($filters['created_after'])) {
            $query->whereDate('files.created_at', '>=', $filters['created_after']);
        }

        if (! empty($filters['created_before'])) {
            $query->whereDate('files.created_at', '<=', $filters['created_before']);
        }

        $sort = in_array($filters['sort'] ?? '', self::SORTS, true)
            ? $filters['sort']
            : 'updated_at';

        $direction = strtolower($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query->orderBy("files.$sort", $direction)->orderBy('files.id', $direction);

        $perPage = min(max((int) ($filters['per_page'] ?? 50), 10), 100);

        /** @var LengthAwarePaginator<int, File> */
        return $query->paginate($perPage)->withQueryString();
    }

    /** @return 'ilike'|'like' */
    private function likeOperator(): string
    {
        return config('database.default') === 'pgsql' ? 'ilike' : 'like';
    }

    private function trigramSearchEnabled(): bool
    {
        if (config('database.default') !== 'pgsql') {
            return false;
        }

        $function = DB::selectOne("SELECT to_regprocedure('similarity(text,text)') IS NOT NULL AS available");

        return filter_var($function?->available ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function matchingFileIds(User $user, string $operator, string $needle, string $search, bool $trigramSearchEnabled): QueryBuilder
    {
        $fileMatches = DB::table('files')
            ->select('files.id')
            ->where('files.user_id', $user->id)
            ->where(function (QueryBuilder $matches) use ($operator, $needle, $search, $trigramSearchEnabled): void {
                $matches->whereRaw("files.name $operator ? ESCAPE '!'", [$needle])
                    ->orWhereRaw("files.description $operator ? ESCAPE '!'", [$needle])
                    ->orWhereRaw("files.mime_type $operator ? ESCAPE '!'", [$needle])
                    ->orWhereRaw("files.file_type $operator ? ESCAPE '!'", [$needle]);

                if ($trigramSearchEnabled) {
                    $matches->orWhereRaw('files.name % ?', [$search])
                        ->orWhereRaw('files.description % ?', [$search]);
                }
            });

        $tagMatches = DB::table('file_tags')
            ->join('tags', 'tags.id', '=', 'file_tags.tag_id')
            ->join('files', 'files.id', '=', 'file_tags.file_id')
            ->where('files.user_id', $user->id)
            ->whereNull('tags.deleted_at')
            ->whereRaw("tags.name $operator ? ESCAPE '!'", [$needle])
            ->select('file_tags.file_id');

        $directoryMatches = DB::table('directories')
            ->select('directories.id')
            ->where(function (QueryBuilder $matches) use ($operator, $needle, $search, $trigramSearchEnabled): void {
                $matches->whereRaw("directories.path $operator ? ESCAPE '!'", [$needle]);

                if ($trigramSearchEnabled) {
                    $matches->orWhereRaw('directories.path % ?', [$search]);
                }
            });

        $pathFileMatches = DB::table('files')
            ->select('files.id')
            ->where('files.user_id', $user->id)
            ->whereIn('files.directory_id', $directoryMatches);

        return $fileMatches->unionAll($tagMatches)->unionAll($pathFileMatches);
    }

    private function orderByRelevance(EloquentBuilder $query, string $operator, string $needle, string $search, bool $trigramSearchEnabled): void
    {
        $query->orderByRaw(
            'CASE WHEN LOWER(files.name) = LOWER(?) THEN 0 '
            ."WHEN files.name $operator ? ESCAPE '!' THEN 1 "
            ."WHEN EXISTS (SELECT 1 FROM directories WHERE directories.id = files.directory_id AND directories.path $operator ? ESCAPE '!') THEN 2 "
            ."WHEN files.description $operator ? ESCAPE '!' THEN 3 ELSE 4 END",
            [$search, $needle, $needle, $needle],
        );

        if ($trigramSearchEnabled) {
            $query->orderByRaw(
                'GREATEST('
                .'similarity(files.name, ?), '
                .'COALESCE(similarity(files.description, ?), 0), '
                .'COALESCE((SELECT similarity(directories.path, ?) FROM directories WHERE directories.id = files.directory_id), 0)'
                .') DESC',
                [$search, $search, $search],
            );
        }
    }
}
