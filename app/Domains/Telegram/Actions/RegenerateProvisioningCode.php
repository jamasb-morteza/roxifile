<?php

namespace App\Domains\Telegram\Actions;

use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Events\RoxiProvisioningStarted;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Services\RoxiProvisioningService;

/**
 * Re-issue a provisioning code for a pending Roxi connection. Invite
 * connections additionally re-dispatch the worker op that delivers the code.
 */
class RegenerateProvisioningCode
{
    public function __construct(
        private readonly RoxiProvisioningService $provisioning,
    ) {}

    public function handle(TelegramConnection $connection): TelegramConnection
    {
        if ($connection->method === TelegramMethod::Invite) {
            return $this->provisioning->regenerateInvite($connection);
        }

        $connection = $this->provisioning->regenerateRoxi($connection);

        broadcast(new RoxiProvisioningStarted(
            $connection,
            $this->provisioning->deepLink(),
            (string) $connection->provision_code_expires_at?->toIso8601String(),
        ));

        return $connection;
    }
}
