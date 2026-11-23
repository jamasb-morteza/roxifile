<?php

namespace App\Domains\Files\Http\Requests;

use App\Domains\Files\Models\Directory;
use App\Domains\Telegram\Enums\TelegramConnectionStatus;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Foundation\Http\FormRequest;

class StoreFileRequest extends FormRequest
{
    /**
     * Ownership + state validation for every id coming from the browser
     * (spec §37): directory and connection must belong to the user, and the
     * connection must be connected.
     */
    public function authorize(): bool
    {
        $directory = $this->resolvedDirectory();
        $connection = $this->resolvedConnection();

        if (($this->filled('directory_id') && $directory === null) || ($directory !== null && ! $this->user()->can('view', $directory))) {
            return false;
        }

        if ($connection === null || ! $this->user()->can('manage', $connection)) {
            return false;
        }

        return $connection->status === TelegramConnectionStatus::Connected;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $protected = $this->resolvedConnection()?->encryption_mode === 'contents_and_metadata';

        return [
            'file' => ['required', 'file', 'max:'.min((int) config('telegram.storage.max_upload_kib', 204800), $this->resolvedConnection()?->method === TelegramMethod::Bot ? 20480 : PHP_INT_MAX)],
            'directory_id' => ['nullable', 'integer'],
            'connection_id' => ['required', 'integer'],
            'encrypted' => $this->resolvedConnection()?->encryption_mode !== 'none' ? ['required', 'boolean', 'accepted'] : ['sometimes', 'boolean'],
            'protected_metadata' => [$protected ? 'required' : 'prohibited', 'nullable', 'array:version,salt,iv,ciphertext'],
            'protected_metadata.version' => ['required_with:protected_metadata', 'integer', 'in:1'],
            'protected_metadata.salt' => ['required_with:protected_metadata', 'string', 'regex:/^[A-Za-z0-9+\/]{43}=$/D'],
            'protected_metadata.iv' => ['required_with:protected_metadata', 'string', 'regex:/^[A-Za-z0-9+\/]{16}$/D'],
            'protected_metadata.ciphertext' => ['required_with:protected_metadata', 'string', 'min:24', 'max:100000', 'regex:/^[A-Za-z0-9+\/]+={0,2}$/D'],
            'name' => [$protected ? 'prohibited' : 'nullable'],
            'description' => [$protected ? 'prohibited' : 'nullable'],
            'tags' => [$protected ? 'prohibited' : 'nullable'],
            'mime_type' => [$protected ? 'prohibited' : 'nullable'],
            'checksum' => [$protected ? 'prohibited' : 'nullable'],
            'original_name' => [$protected ? 'prohibited' : 'nullable'],
            'plaintext_mime' => ['nullable', ...($protected ? ['prohibited'] : []), 'string', 'max:255', 'regex:/^[a-zA-Z0-9!#$&^_.+\-]+\/[a-zA-Z0-9!#$&^_.+\-]+$/D'],
        ];
    }

    /** @return array{version: int, salt: string, iv: string, ciphertext: string}|null */
    public function protectedMetadata(): ?array
    {
        $metadata = $this->validated('protected_metadata');
        if ($metadata === null) {
            return null;
        }
        $metadata['version'] = (int) $metadata['version'];

        return $metadata;
    }

    public function resolvedDirectory(): ?Directory
    {
        $id = $this->input('directory_id');

        if ($id === null || $id === '' || (int) $id === 0) {
            return null;
        }

        return Directory::query()->find((int) $id);
    }

    public function resolvedConnection(): ?TelegramConnection
    {
        $id = $this->integer('connection_id');

        return $id === 0 ? null : TelegramConnection::query()->find($id);
    }
}
