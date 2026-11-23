<?php

namespace App\Domains\Telegram\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBotCredentialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:10', 'max:64', 'regex:/^\d+:[A-Za-z0-9_-]+$/'],
        ];
    }
}
