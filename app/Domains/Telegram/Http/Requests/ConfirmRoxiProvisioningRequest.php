<?php

namespace App\Domains\Telegram\Http\Requests;

use App\Domains\Telegram\Data\ConfirmRoxiProvisioningData;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmRoxiProvisioningRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:64'],
        ];
    }

    public function toDto(): ConfirmRoxiProvisioningData
    {
        return new ConfirmRoxiProvisioningData(trim((string) $this->validated('code')));
    }
}
