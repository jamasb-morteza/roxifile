<?php

namespace App\Domains\Files\Http\Requests;

use App\Domains\Files\Data\CopyFileData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CopyFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('file')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'connection_id' => ['prohibited'],
            'telegram_connection_id' => ['prohibited'],
            'directory_id' => ['nullable', 'integer', Rule::exists('directories', 'id')->where('user_id', $this->user()?->id)->whereNull('deleted_at')],
            'name' => ['nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1f\/\\\\]/u'],
            'request_id' => ['nullable', 'uuid'],
        ];
    }

    public function toDto(): CopyFileData
    {
        return new CopyFileData($this->integer('directory_id') ?: null, $this->input('name'), $this->input('request_id'));
    }
}
