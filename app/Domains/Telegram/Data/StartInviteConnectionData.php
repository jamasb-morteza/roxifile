<?php

namespace App\Domains\Telegram\Data;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Controller→action contract for starting Invite provisioning: designate a
 * target account by @username or mobile number.
 */
final readonly class StartInviteConnectionData
{
    public function __construct(
        public string $name,
        public string $target,
        public string $setupMode = 'existing',
        public ?string $groupName = null,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        return new self(
            name: (string) $request->string('name'),
            target: (string) $request->string('target'),
            setupMode: (string) $request->string('setup_mode', 'existing'),
            groupName: $request->filled('group_name') ? trim((string) $request->string('group_name')) : null,
        );
    }
}
