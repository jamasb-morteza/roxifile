<?php

namespace App\Domains\Telegram\Http\Requests;

use App\Domains\Telegram\Data\PersonalTelegramConnectionData;
use Illuminate\Foundation\Http\FormRequest;

class StartPersonalTelegramConnectionRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:64'],
            'phone' => ['required', 'string', 'max:16', 'regex:/^\+[1-9][0-9]{5,14}$/'],
        ];
    }

    public function toDto(): PersonalTelegramConnectionData
    {
        return new PersonalTelegramConnectionData(
            $this->string('name')->trim()->toString(),
            $this->string('phone')->trim()->toString(),
        );
    }

    public function messages(): array
    {
        return ['phone.regex' => 'Enter a valid phone number with its country code.'];
    }
}
