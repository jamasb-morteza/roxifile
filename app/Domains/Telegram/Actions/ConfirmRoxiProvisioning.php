<?php

namespace App\Domains\Telegram\Actions;

use App\Domains\Telegram\Data\ConfirmRoxiProvisioningData;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Services\RoxiProvisioningService;

class ConfirmRoxiProvisioning
{
    public function __construct(
        private readonly RoxiProvisioningService $provisioning,
    ) {}

    public function handle(TelegramConnection $connection, ConfirmRoxiProvisioningData $data): TelegramConnection
    {
        return $this->provisioning->confirm($connection, $data->code);
    }
}
