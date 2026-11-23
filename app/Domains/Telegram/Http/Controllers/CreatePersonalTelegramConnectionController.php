<?php

namespace App\Domains\Telegram\Http\Controllers;

use App\Domains\Telegram\Actions\ManagePersonalTelegramConnection;
use App\Domains\Telegram\Http\Requests\StartPersonalTelegramConnectionRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class CreatePersonalTelegramConnectionController extends Controller
{
    public function __invoke(StartPersonalTelegramConnectionRequest $request, ManagePersonalTelegramConnection $action): JsonResponse
    {
        $connection = $action->start($request->user(), $request->toDto());

        return response()->json([
            'connection' => [
                'id' => $connection->id,
                'name' => $connection->name,
                'method' => $connection->method->value,
                'status' => $connection->status->value,
                'personal_login_step' => $connection->metadata['personal_login_step'],
            ],
        ], 202);
    }
}
