<?php

namespace App\Domains\Telegram\Http\Requests;

use App\Domains\Telegram\Data\SetupRoxiStorageChatData;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Foundation\Http\FormRequest;

class SetupRoxiStorageChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        $connection = $this->route('connection');

        return $connection instanceof TelegramConnection && ($this->user()?->can('manage', $connection) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['mode' => ['required', 'string', 'in:discover,create'], 'group_name' => ['nullable', 'string', 'min:1', 'max:128']];
    }

    public function toDto(): SetupRoxiStorageChatData
    {
        return new SetupRoxiStorageChatData((string) $this->string('mode'), $this->filled('group_name') ? trim((string) $this->string('group_name')) : null);
    }
}
