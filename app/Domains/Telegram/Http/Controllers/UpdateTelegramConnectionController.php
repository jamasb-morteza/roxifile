<?php

namespace App\Domains\Telegram\Http\Controllers;

use App\Domains\Telegram\Actions\ManageTelegramConnection;
use App\Domains\Telegram\Http\Requests\UpdateTelegramConnectionRequest;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Http\JsonResponse;

class UpdateTelegramConnectionController
{
    public function __invoke(UpdateTelegramConnectionRequest $request, TelegramConnection $connection, ManageTelegramConnection $action): JsonResponse
    {
        $connection = $action->update($connection, $request->toDto());

        return response()->json(['connection' => ['id' => $connection->id, 'name' => $connection->name, 'status' => $connection->status->value, 'sync_caption_tags' => $connection->syncsCaptionTags()]]);
    }
}
