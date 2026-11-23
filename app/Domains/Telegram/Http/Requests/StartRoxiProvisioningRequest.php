<?php

namespace App\Domains\Telegram\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartRoxiProvisioningRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:1', 'max:64'],
        ];
    }
}
