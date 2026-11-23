<?php

namespace App\Domains\Telegram\Http\Controllers;

use App\Domains\Telegram\Actions\CreateBotConnection;
use App\Domains\Telegram\Data\CreateBotConnectionData;
use App\Domains\Telegram\Http\Requests\CreateBotConnectionRequest;
use App\Domains\Telegram\Services\BotProvisioningService;
use Illuminate\Http\JsonResponse;

class CreateBotConnectionController
{
    public function __invoke(
        CreateBotConnectionRequest $request,
        CreateBotConnection $action,
    ): JsonResponse {
        $result = $action->handle(
            $request->user(),
            CreateBotConnectionData::fromRequest($request),
        );

        $connection = $result['connection'];

        return response()->json([
            'connection' => [
                'id' => $connection->id,
                'name' => $connection->name,
                'method' => $connection->method->value,
                'status' => $connection->status->value,
            ],
            'bot_username' => $result['bot_username'],
            'code' => $connection->getAttribute(BotProvisioningService::TRANSIENT_CODE_ATTRIBUTE),
            'deep_links' => $result['deep_links'],
            'expires_at' => $result['expires_at'],
        ], 201);
    }
}
