<?php

namespace App\Domains\Telegram\Http\Requests;

use App\Domains\Telegram\Data\CompleteRoxiLoginCodeData;
use Illuminate\Foundation\Http\FormRequest;

class CompleteRoxiLoginCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:4', 'max:12', 'regex:/^[A-Za-z0-9-]+$/'],
        ];
    }

    public function toDto(): CompleteRoxiLoginCodeData
    {
        return new CompleteRoxiLoginCodeData($this->string('code')->toString());
    }
}
