<?php

namespace App\Domains\Telegram\Http\Controllers;

use App\Domains\Telegram\Actions\StartInviteConnection;
use App\Domains\Telegram\Data\StartInviteConnectionData;
use App\Domains\Telegram\Http\Requests\StartInviteConnectionRequest;
use App\Domains\Telegram\Services\RoxiProvisioningService;
use Illuminate\Http\JsonResponse;

class StartInviteConnectionController
{
    public function __invoke(
        StartInviteConnectionRequest $request,
        StartInviteConnection $action,
    ): JsonResponse {
        $connection = $action->handle(
            $request->user(),
            StartInviteConnectionData::fromRequest($request),
        );

        return response()->json([
            'connection' => [
                'id' => $connection->id,
                'name' => $connection->name,
                'method' => $connection->method->value,
                'status' => $connection->status->value,
                'target_identifier' => $connection->target_identifier,
            ],
            'code' => $connection->getAttribute(RoxiProvisioningService::TRANSIENT_CODE_ATTRIBUTE),
            'deep_link' => app(RoxiProvisioningService::class)->deepLink(),
            'expires_at' => $connection->provision_code_expires_at?->toIso8601String(),
        ], 201);
    }
}
