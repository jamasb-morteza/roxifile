<?php

namespace App\Domains\Telegram\Data;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Controller→action contract for connecting a Telegram Bot (spec §6).
 */
final readonly class CreateBotConnectionData
{
    public function __construct(
        public string $name,
        public string $token,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        return new self(
            name: (string) $request->string('name'),
            token: (string) $request->string('token'),
        );
    }
}
