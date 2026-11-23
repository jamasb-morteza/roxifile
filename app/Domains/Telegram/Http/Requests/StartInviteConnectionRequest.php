<?php

namespace App\Domains\Telegram\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartInviteConnectionRequest extends FormRequest
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
            'setup_mode' => ['sometimes', 'required', 'in:existing,create'],
            'group_name' => ['nullable', 'string', 'min:1', 'max:128', 'required_if:setup_mode,create'],
            'name' => ['required', 'string', 'min:1', 'max:64'],
            'target' => [
                'required',
                'string',
                'max:64',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $target = trim((string) $value);
                    $isPhone = preg_match('/^\+?[0-9]{6,15}$/', $target) === 1;
                    $isUsername = preg_match('/^@?[A-Za-z0-9_]{4,32}$/', $target) === 1;

                    if (! $isPhone && ! $isUsername) {
                        $fail('Enter a Telegram @username or mobile number.');
                    }
                },
            ],
        ];
    }
}
