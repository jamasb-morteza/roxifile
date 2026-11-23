<?php

namespace App\Domains\Identity\Http\Requests\Settings;

use App\Domains\Identity\Concerns\PasswordValidationRules;
use App\Domains\Identity\Data\UpdateUserPasswordData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PasswordUpdateRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => $this->currentPasswordRules(),
            'password' => $this->passwordRules(),
        ];
    }

    /**
     * Convert the validated input to a DTO.
     */
    public function toDto(): UpdateUserPasswordData
    {
        return new UpdateUserPasswordData(password: $this->validated('password'));
    }
}
