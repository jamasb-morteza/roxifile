<?php

namespace App\Domains\Files\Http\Requests;

use App\Domains\Files\Data\ProtectedMetadataData;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProtectedMetadataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('file')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [];
        foreach (['protected_metadata', 'previous_metadata'] as $prefix) {
            $rules[$prefix] = ['required', 'array:version,salt,iv,ciphertext'];
            $rules[$prefix.'.version'] = ['required', 'integer', 'in:1'];
            $rules[$prefix.'.salt'] = ['required', 'string', 'regex:/^[A-Za-z0-9+\/]{43}=$/D'];
            $rules[$prefix.'.iv'] = ['required', 'string', 'regex:/^[A-Za-z0-9+\/]{16}$/D'];
            $rules[$prefix.'.ciphertext'] = ['required', 'string', 'min:24', 'max:100000', 'regex:/^[A-Za-z0-9+\/]+={0,2}$/D'];
        }
        foreach (['name', 'description', 'tags', 'mime_type', 'plaintext_mime', 'checksum', 'original_name'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    public function toDto(): ProtectedMetadataData
    {
        return new ProtectedMetadataData($this->validated('protected_metadata'), $this->validated('previous_metadata'));
    }
}
