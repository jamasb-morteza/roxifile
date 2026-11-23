<?php

namespace App\Domains\Telegram\Http\Requests;

use App\Domains\Telegram\Data\PersonalTelegramLoginPasswordData;
use Illuminate\Foundation\Http\FormRequest;

class SubmitPersonalTelegramLoginPasswordRequest extends FormRequest
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
            'password' => ['required', 'string', 'min:1', 'max:256'],
        ];
    }

    public function toDto(): PersonalTelegramLoginPasswordData
    {
        return new PersonalTelegramLoginPasswordData((int) $this->route('connection')->id, $this->string('password')->toString());
    }
}
