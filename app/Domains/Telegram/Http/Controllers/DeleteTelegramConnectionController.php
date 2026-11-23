<?php

namespace App\Domains\Telegram\Http\Controllers;

use App\Domains\Telegram\Actions\ManageTelegramConnection;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeleteTelegramConnectionController
{
    use AuthorizesRequests;

    public function __invoke(TelegramConnection $connection, ManageTelegramConnection $action, Request $request): JsonResponse
    {
        $this->authorize('delete', $connection);
        $validated = $request->validate(['delete_files' => ['sometimes', 'required', 'boolean']]);
        $deleted = $action->delete($connection, (bool) ($validated['delete_files'] ?? false));

        return response()->json(['deleted' => $deleted], $deleted ? 200 : 202);
    }
}
