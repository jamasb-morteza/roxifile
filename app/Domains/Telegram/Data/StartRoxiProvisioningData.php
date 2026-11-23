<?php

namespace App\Domains\Telegram\Data;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Controller→action contract for starting Roxi provisioning.
 */
final readonly class StartRoxiProvisioningData
{
    public function __construct(
        public string $name,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        return new self(
            name: (string) $request->string('name'),
        );
    }
}
