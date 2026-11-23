<?php

namespace App\Domains\Telegram\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Data\StartRoxiProvisioningData;
use App\Domains\Telegram\Enums\TelegramAccountStatus;
use App\Domains\Telegram\Models\TelegramAccount;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Services\RoxiProvisioningService;

/**
 * Start Roxi provisioning for a user (spec §8): pending connection + code.
 * Also ensures the system Roxi account exists and is not in an error state.
 */
class StartRoxiProvisioning
{
    public function __construct(
        private readonly RoxiProvisioningService $provisioning,
    ) {}

    public function handle(User $user, StartRoxiProvisioningData $data): TelegramConnection
    {
        $roxi = TelegramAccount::query()
            ->where('method', 'roxi')
            ->where('is_system', true)
            ->first();

        if ($roxi !== null && $roxi->status === TelegramAccountStatus::Error) {
            abort(503, 'The Roxi shared account is temporarily unavailable. Please try again later.');
        }

        return $this->provisioning->start($user, $data->name);
    }
}
