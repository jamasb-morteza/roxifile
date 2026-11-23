<?php

namespace App\Domains\Telegram\Http\Requests;

use App\Domains\Telegram\Data\CompleteRoxiLoginPasswordData;
use Illuminate\Foundation\Http\FormRequest;

class CompleteRoxiLoginPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:1', 'max:256'],
        ];
    }

    public function toDto(): CompleteRoxiLoginPasswordData
    {
        return new CompleteRoxiLoginPasswordData($this->string('password')->toString());
    }
}
