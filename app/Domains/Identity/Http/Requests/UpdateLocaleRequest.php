<?php

namespace App\Domains\Identity\Http\Requests;

use App\Domains\Identity\Data\LocaleData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocaleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', Rule::in(['en', 'fa'])],
        ];
    }

    /**
     * Convert the validated locale into its domain input.
     */
    public function toDto(): LocaleData
    {
        /** @var array{locale: string} $validated */
        $validated = $this->validated();

        return new LocaleData($validated['locale']);
    }
}
