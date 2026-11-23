<?php

namespace App\Domains\Telegram\Http\Controllers;

use App\Domains\Telegram\Actions\ManagePersonalTelegramConnection;
use App\Domains\Telegram\Http\Requests\SubmitPersonalTelegramLoginPasswordRequest;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class SubmitPersonalTelegramLoginPasswordController extends Controller
{
    public function __invoke(SubmitPersonalTelegramLoginPasswordRequest $request, TelegramConnection $connection, ManagePersonalTelegramConnection $action): JsonResponse
    {
        $connection = $action->submitPassword($request->user(), $request->toDto());

        return response()->json(['connection' => ['id' => $connection->id, 'status' => $connection->status->value]], 202);
    }
}
