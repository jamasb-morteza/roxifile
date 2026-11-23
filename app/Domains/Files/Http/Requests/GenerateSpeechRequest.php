<?php

declare(strict_types=1);

namespace App\Domains\Files\Http\Requests;

use App\Domains\Files\Data\GenerateSpeechData;
use Illuminate\Foundation\Http\FormRequest;

class GenerateSpeechRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:3000'],
            'voice' => ['sometimes', 'string', 'regex:/\A[A-Za-z0-9_-]{1,128}\z/'],
        ];
    }

    public function toDto(string $requestId): GenerateSpeechData
    {
        $input = $this->validated();

        return new GenerateSpeechData(
            $input['text'],
            $input['voice'] ?? (string) config('services.ai_tts.voice', 'default-female'),
            $requestId,
        );
    }
}
