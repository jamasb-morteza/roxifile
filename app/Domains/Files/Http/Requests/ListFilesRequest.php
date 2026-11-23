<?php

namespace App\Domains\Files\Http\Requests;

use App\Domains\Files\Actions\SearchFiles;
use App\Domains\Files\Data\SearchFilesData;
use App\Domains\Files\Enums\FileType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListFilesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'q' => ['nullable', 'string', 'max:255'],
            'file' => ['nullable', Rule::anyOf([['integer', 'min:1'], ['uuid']])],
            'directory_id' => ['nullable', 'integer', 'min:1'],
            'file_type' => ['nullable', Rule::enum(FileType::class)],
            'mime_type' => ['nullable', 'string', 'max:255'],
            'telegram_account_id' => ['nullable', 'integer', 'min:1'],
            'telegram_connection_id' => ['nullable', 'integer', 'min:1'],
            'tag_ids' => ['nullable', 'array', 'max:20'],
            'tag_ids.*' => ['integer', 'min:1'],
            'created_after' => ['nullable', 'date'],
            'created_before' => ['nullable', 'date'],
            'sort' => ['nullable', Rule::in(SearchFiles::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'trash' => ['nullable', 'boolean'],
            'scope' => ['nullable', Rule::in(['folder', 'all'])],
        ];
        if ($this->filled('created_after')) {
            $rules['created_before'][] = 'after_or_equal:created_after';
        }

        return $rules;
    }

    public function toDto(): SearchFilesData
    {
        return new SearchFilesData(
            q: $this->string('q')->toString() ?: null,
            fileType: $this->input('file_type'), mimeType: $this->input('mime_type'),
            tagIds: array_values(array_map(intval(...), $this->input('tag_ids', []))),
            telegramAccountId: $this->integer('telegram_account_id') ?: null,
            telegramConnectionId: $this->integer('telegram_connection_id') ?: null,
            createdAfter: $this->input('created_after'), createdBefore: $this->input('created_before'),
            directoryId: $this->integer('directory_id') ?: null,
            sort: $this->input('sort'), direction: $this->input('direction'),
            perPage: $this->integer('per_page') ?: 50, trash: $this->boolean('trash'),
            global: $this->input('scope') === 'all',
        );
    }
}
