<?php

namespace App\Domains\Telegram\Http\Requests;

use App\Domains\Telegram\Data\UpdateTelegramConnectionData;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTelegramConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('connection'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => ['sometimes', 'required', 'string', 'max:64'], 'enabled' => ['sometimes', 'required', 'boolean'], 'sync_caption_tags' => ['sometimes', 'required', 'boolean']];
    }

    public function toDto(): UpdateTelegramConnectionData
    {
        return new UpdateTelegramConnectionData($this->validated('name'), $this->has('enabled') ? $this->boolean('enabled') : null, $this->has('sync_caption_tags') ? $this->boolean('sync_caption_tags') : null);
    }
}
