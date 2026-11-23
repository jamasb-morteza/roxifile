<?php

namespace App\Domains\Telegram\Http\Controllers;

use App\Domains\Telegram\Actions\ConfigureConnectionEncryption;
use App\Domains\Telegram\Http\Requests\ConfigureConnectionEncryptionRequest;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ConfigureConnectionEncryptionController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(ConfigureConnectionEncryptionRequest $request, TelegramConnection $connection, ConfigureConnectionEncryption $action): JsonResponse
    {
        $connection = $action->handle($request->user(), $connection, $request->toDto());

        return response()->json(['encryption_mode' => $connection->encryption_mode]);
    }
}
