<?php

namespace App\Domains\Telegram\Http\Requests;

use App\Domains\Telegram\Data\ConfigureConnectionEncryptionData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConfigureConnectionEncryptionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->route('connection')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'scope' => ['sometimes', 'required', 'in:contents,contents_and_metadata'],
            'previous_envelope' => ['sometimes', 'required', 'array:version,salt,iterations,password,recovery'],
            'envelope' => ['required', 'array:version,salt,iterations,password,recovery'],
            'envelope.version' => ['required', 'integer', 'in:1'],
            'envelope.iterations' => ['required', 'integer', 'in:600000'],
            'envelope.salt' => ['required', 'string', 'regex:/^[A-Za-z0-9+\/]{22}==$/D'],
            'envelope.password' => ['required', 'array:iv,ciphertext'],
            'envelope.recovery' => ['required', 'array:iv,ciphertext'],
            'envelope.password.iv' => ['required', 'string', 'regex:/^[A-Za-z0-9+\/]{16}$/D'],
            'envelope.recovery.iv' => ['required', 'string', 'regex:/^[A-Za-z0-9+\/]{16}$/D'],
            'envelope.password.ciphertext' => ['required', 'string', 'regex:/^[A-Za-z0-9+\/]{64}$/D'],
            'envelope.recovery.ciphertext' => ['required', 'string', 'regex:/^[A-Za-z0-9+\/]{64}$/D'],
        ];
    }

    public function toDto(): ConfigureConnectionEncryptionData
    {
        return new ConfigureConnectionEncryptionData($this->validated('envelope'), $this->validated('previous_envelope'), $this->validated('scope'));
    }
}
