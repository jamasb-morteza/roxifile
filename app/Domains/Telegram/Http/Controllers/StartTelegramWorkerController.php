<?php

namespace App\Domains\Telegram\Http\Controllers;

use App\Domains\Telegram\Actions\StartTelegramWorker;
use App\Domains\Telegram\Enums\WorkerStartOutcome;
use Illuminate\Http\JsonResponse;

/**
 * Super-admin-only (can:start-telegram-worker): boots the detached worker.
 */
class StartTelegramWorkerController
{
    public function __invoke(StartTelegramWorker $action): JsonResponse
    {
        $outcome = $action->handle();

        if ($outcome === WorkerStartOutcome::LoginInProgress) {
            return response()->json([
                'outcome' => $outcome->value,
                'message' => 'Finish or cancel the Roxi sign-in before starting the worker.',
            ], 409);
        }

        return response()->json(['outcome' => $outcome->value]);
    }
}
