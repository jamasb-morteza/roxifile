<?php

namespace App\Domains\Telegram\Http\Requests;

use App\Domains\Telegram\Data\PersonalTelegramReconnectData;
use Illuminate\Foundation\Http\FormRequest;

class PersonalTelegramReconnectRequest extends FormRequest
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
            'phone' => ['required', 'string', 'max:16', 'regex:/^\+[1-9][0-9]{5,14}$/'],
        ];
    }

    public function toDto(): PersonalTelegramReconnectData
    {
        return new PersonalTelegramReconnectData((int) $this->route('connection')->id, $this->string('phone')->trim()->toString());
    }

    public function messages(): array
    {
        return ['phone.regex' => 'Enter a valid phone number with its country code.'];
    }
}
