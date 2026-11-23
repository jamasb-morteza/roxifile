<?php

namespace App\Domains\Telegram\Http\Controllers;

use App\Domains\Telegram\Actions\ManagePersonalTelegramConnection;
use App\Domains\Telegram\Http\Requests\SubmitPersonalTelegramLoginCodeRequest;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class SubmitPersonalTelegramLoginCodeController extends Controller
{
    public function __invoke(SubmitPersonalTelegramLoginCodeRequest $request, TelegramConnection $connection, ManagePersonalTelegramConnection $action): JsonResponse
    {
        $connection = $action->submitCode($request->user(), $request->toDto());

        return response()->json(['connection' => ['id' => $connection->id, 'status' => $connection->status->value]], 202);
    }
}
