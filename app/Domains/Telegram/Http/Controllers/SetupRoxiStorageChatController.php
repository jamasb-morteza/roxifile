<?php

namespace App\Domains\Telegram\Http\Controllers;

use App\Domains\Telegram\Actions\SetupRoxiStorageChat;
use App\Domains\Telegram\Http\Requests\SetupRoxiStorageChatRequest;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Http\JsonResponse;

class SetupRoxiStorageChatController
{
    public function __invoke(TelegramConnection $connection, SetupRoxiStorageChatRequest $request, SetupRoxiStorageChat $action): JsonResponse
    {
        $action->queue($connection, $request->toDto());

        return response()->json(['queued' => true], 202);
    }
}
