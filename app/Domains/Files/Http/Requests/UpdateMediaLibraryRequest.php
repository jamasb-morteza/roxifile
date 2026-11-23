<?php

namespace App\Domains\Files\Http\Requests;

use App\Domains\Files\Data\UpdateMediaLibraryData;
use App\Domains\Files\Models\File;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMediaLibraryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $file = $this->route('file');

        return ! $file instanceof File || $this->user()->can('view', $file);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $owned = fn (Builder $query): Builder => $query->where('user_id', $this->user()->id)->whereNull('deleted_at');
        $media = Rule::exists('files', 'id')->where(fn (Builder $query): Builder => $owned($query)
            ->where(fn (Builder $media): Builder => $media->whereIn('file_type', ['audio', 'video'])->orWhereNotNull('encryption_header'))->where('status', 'uploaded'));

        $artwork = ['sometimes', 'nullable', 'integer', Rule::exists('files', 'id')->where(fn (Builder $query): Builder => $owned($query)
            ->where('file_type', 'image')->where('status', 'uploaded')->whereNull('encryption_header')
            ->whereIn('mime_type', File::ARTWORK_MIME_TYPES))];

        return match ($this->route()->getName()) {
            'media.playlists.destroy', 'media.folders.destroy' => [],
            'media.folders.store', 'media.folders.update' => [
                'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:100'],
                'parent_id' => ['sometimes', 'nullable', 'integer'],
            ],
            'media.library' => ['directory_id' => ['present', 'nullable', 'integer', Rule::exists('directories', 'id')->where($owned)]],
            'media.tracks.playback' => ['event_id' => ['required', 'uuid']],
            'media.tracks.update' => [
                'favorite' => ['sometimes', 'boolean'],
                'rating' => ['sometimes', 'nullable', 'integer', 'between:1,10'],
                'details' => ['sometimes', 'array:title,artist,album,genre,track_number,lyrics,cover_file_id,album_cover_file_id'],
                'details.title' => ['sometimes', 'nullable', 'string', 'max:255'],
                'details.artist' => ['sometimes', 'nullable', 'string', 'max:255'],
                'details.album' => ['sometimes', 'nullable', 'string', 'max:255'],
                'details.genre' => ['sometimes', 'nullable', 'string', 'max:255'],
                'details.track_number' => ['sometimes', 'nullable', 'integer', 'between:1,9999'],
                'details.lyrics' => ['sometimes', 'nullable', 'string', 'max:10000'],
                'details.cover_file_id' => $artwork,
                'details.album_cover_file_id' => $artwork,
            ],
            default => [
                'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:100'],
                'cover_file_id' => $artwork,
                'smart_rules' => ['sometimes', 'array:tag,minimum_rating,favorite_only,metadata,minimum_plays', 'min:1', 'prohibits:file_ids,ordered_file_ids,add_file_ids,add_file_id,remove_file_id'],
                'smart_rules.tag' => ['sometimes', 'nullable', 'string', 'max:255'],
                'smart_rules.minimum_rating' => ['sometimes', 'nullable', 'integer', 'between:1,10'],
                'smart_rules.favorite_only' => ['sometimes', 'boolean'],
                'smart_rules.metadata' => ['sometimes', 'nullable', 'string', 'max:255'],
                'smart_rules.minimum_plays' => ['sometimes', 'nullable', 'integer', 'between:1,100000'],

                'folder_id' => ['sometimes', 'nullable', 'integer'],
                'file_ids' => ['sometimes', 'array', 'list', 'max:1000'],
                'file_ids.*' => ['integer', 'distinct', $media],
                'ordered_file_ids' => $this->isMethod('patch')
                    ? ['sometimes', 'array', 'list', 'max:1000', 'prohibits:file_ids,add_file_ids,add_file_id,remove_file_id']
                    : ['prohibited'],
                'ordered_file_ids.*' => ['integer', 'distinct'],
                'add_file_ids' => $this->isMethod('patch')
                    ? ['sometimes', 'array', 'list', 'min:1', 'max:1000', 'prohibits:file_ids,add_file_id,remove_file_id']
                    : ['prohibited'],
                'add_file_ids.*' => ['integer', 'distinct'],
                'add_file_id' => ['sometimes', 'integer', 'prohibits:remove_file_id', $media],
                'remove_file_id' => ['sometimes', 'integer'],
            ],
        };
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->routeIs('media.playlists.update') || ! $this->has('add_file_ids')) {
                return;
            }

            $ids = $validator->validated()['add_file_ids'];
            $count = DB::table('files')
                ->whereIntegerInRaw('id', $ids)
                ->where('user_id', $this->user()->id)
                ->whereNull('deleted_at')
                ->where('status', 'uploaded')
                ->where(fn (Builder $query): Builder => $query->whereIn('file_type', ['audio', 'video'])->orWhereNotNull('encryption_header'))
                ->count();

            if ($count !== count($ids)) {
                $validator->errors()->add('add_file_ids', 'Some selected tracks are unavailable.');
            }
        }];
    }

    public function toDto(): UpdateMediaLibraryData
    {
        return new UpdateMediaLibraryData($this->user()->id, $this->validated());
    }
}
