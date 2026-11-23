<?php

namespace App\Domains\Telegram\Http\Controllers;

use App\Domains\Telegram\Actions\StartRoxiProvisioning;
use App\Domains\Telegram\Data\StartRoxiProvisioningData;
use App\Domains\Telegram\Http\Requests\StartRoxiProvisioningRequest;
use App\Domains\Telegram\Services\RoxiProvisioningService;
use Illuminate\Http\JsonResponse;

class StartRoxiProvisioningController
{
    public function __invoke(
        StartRoxiProvisioningRequest $request,
        StartRoxiProvisioning $action,
    ): JsonResponse {
        $connection = $action->handle(
            $request->user(),
            StartRoxiProvisioningData::fromRequest($request),
        );

        return response()->json([
            'connection' => [
                'id' => $connection->id,
                'name' => $connection->name,
                'method' => $connection->method->value,
                'status' => $connection->status->value,
            ],
            'code' => $connection->getAttribute(RoxiProvisioningService::TRANSIENT_CODE_ATTRIBUTE),
            'deep_link' => $this->deepLink($request),
            'expires_at' => $connection->provision_code_expires_at?->toIso8601String(),
        ], 201);
    }

    private function deepLink(StartRoxiProvisioningRequest $request): string
    {
        return app(RoxiProvisioningService::class)->deepLink();
    }
}
