<?php

namespace App\Domains\Telegram\Http\Requests;

use App\Domains\Telegram\Data\PersonalTelegramLoginCodeData;
use Illuminate\Foundation\Http\FormRequest;

class SubmitPersonalTelegramLoginCodeRequest extends FormRequest
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
            'code' => ['required', 'string', 'min:4', 'max:12', 'regex:/^[A-Za-z0-9-]+$/'],
        ];
    }

    public function toDto(): PersonalTelegramLoginCodeData
    {
        return new PersonalTelegramLoginCodeData((int) $this->route('connection')->id, $this->string('code')->toString());
    }
}
