<?php

namespace App\Domains\Telegram\Http\Controllers;

use App\Domains\Telegram\Actions\ConfirmRoxiProvisioning;
use App\Domains\Telegram\Http\Requests\ConfirmRoxiProvisioningRequest;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class ConfirmRoxiProvisioningController
{
    use AuthorizesRequests;

    public function __invoke(
        TelegramConnection $connection,
        ConfirmRoxiProvisioningRequest $request,
        ConfirmRoxiProvisioning $action,
    ): JsonResponse {
        $this->authorize('manage', $connection);

        $connection = $action->handle($connection, $request->toDto());

        return response()->json([
            'confirmed' => true,
            'connection' => [
                'id' => $connection->id,
                'status' => $connection->status->value,
            ],
        ]);
    }
}
