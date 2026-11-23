<?php

namespace App\Domains\Files\Http\Requests;

use App\Domains\Files\Data\BulkTagFilesData;
use Illuminate\Foundation\Http\FormRequest;

class BulkAttachTagsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file_ids' => ['required', 'array', 'min:1', 'max:100'],
            'file_ids.*' => ['integer', 'distinct'],
            'tags' => ['required', 'array', 'min:1', 'max:20'],
            'tags.*' => ['required', 'string', 'max:32'],
        ];
    }

    public function toDto(): BulkTagFilesData
    {
        return new BulkTagFilesData(
            array_values(array_map(intval(...), $this->validated('file_ids'))),
            array_values(array_map(strval(...), $this->validated('tags'))),
        );
    }
}
