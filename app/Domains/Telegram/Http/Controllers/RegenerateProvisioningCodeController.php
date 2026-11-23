<?php

namespace App\Domains\Telegram\Http\Controllers;

use App\Domains\Telegram\Actions\RegenerateProvisioningCode;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Services\RoxiProvisioningService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class RegenerateProvisioningCodeController
{
    use AuthorizesRequests;

    public function __invoke(TelegramConnection $connection, RegenerateProvisioningCode $action): JsonResponse
    {
        $this->authorize('manage', $connection);

        $connection = $action->handle($connection);

        return response()->json([
            'connection_id' => $connection->id,
            'code' => $connection->getAttribute(RoxiProvisioningService::TRANSIENT_CODE_ATTRIBUTE),
            'deep_link' => app(RoxiProvisioningService::class)->deepLink(),
            'expires_at' => $connection->provision_code_expires_at?->toIso8601String(),
        ]);
    }
}
