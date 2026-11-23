<?php

namespace App\Domains\Files\Http\Requests;

use App\Domains\Files\Data\UpdateFileDescriptionData;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFileDescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('file')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['description' => ['nullable', 'string', 'max:5000']];
    }

    public function toDto(): UpdateFileDescriptionData
    {
        return new UpdateFileDescriptionData($this->validated('description'));
    }
}
