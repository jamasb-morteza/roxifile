<?php

namespace App\Domains\Telegram\Http\Controllers;

use App\Domains\Telegram\Actions\ManagePersonalTelegramConnection;
use App\Domains\Telegram\Http\Requests\PersonalTelegramReconnectRequest;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ReconnectPersonalTelegramConnectionController extends Controller
{
    public function __invoke(PersonalTelegramReconnectRequest $request, TelegramConnection $connection, ManagePersonalTelegramConnection $action): JsonResponse
    {
        $connection = $action->reconnect($request->user(), $request->toDto());

        return response()->json(['connection' => ['id' => $connection->id, 'status' => $connection->status->value]], 202);
    }
}
