<?php

namespace App\Domains\Identity\Http\Requests\Settings;

use App\Domains\Identity\Concerns\ProfileValidationRules;
use App\Domains\Identity\Data\UpdateProfileData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->profileRules($this->user()->id);
    }

    /**
     * Convert the validated input to a DTO.
     */
    public function toDto(): UpdateProfileData
    {
        return new UpdateProfileData(
            name: $this->validated('name'),
            email: $this->validated('email'),
        );
    }
}
