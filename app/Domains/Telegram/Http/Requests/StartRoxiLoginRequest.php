<?php

namespace App\Domains\Telegram\Http\Requests;

use App\Domains\Telegram\Data\StartRoxiLoginData;
use Illuminate\Foundation\Http\FormRequest;

class StartRoxiLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
        ];
    }

    public function toDto(): StartRoxiLoginData
    {
        return new StartRoxiLoginData($this->string('phone')->toString());
    }
}
