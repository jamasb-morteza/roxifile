<?php

namespace App\Domains\Telegram\Http\Controllers;

use App\Domains\Telegram\Actions\VerifyTelegramConnection;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerifyTelegramConnectionController
{
    use AuthorizesRequests;

    public function __invoke(TelegramConnection $connection, Request $request, VerifyTelegramConnection $action): JsonResponse
    {
        $this->authorize('manage', $connection);

        $request->validate(['chat_ref' => ['nullable', 'string', 'max:255']]);
        $action->queue($connection, $request->string('chat_ref')->toString() ?: null);
        $connection->refresh();

        return response()->json([
            'queued' => true,
            'connection' => [
                'id' => $connection->id,
                'name' => $connection->name,
                'status' => $connection->status->value,
                'chat_id' => $connection->chat_id,
                'chat_title' => $connection->chat_title,
                'chat_username' => $connection->chat_username,
                'chat_type' => $connection->chat_type?->value,
                'last_verified_at' => $connection->last_verified_at?->toIso8601String(),
            ],
        ], 202);
    }
}
