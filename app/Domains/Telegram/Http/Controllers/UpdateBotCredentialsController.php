<?php

namespace App\Domains\Telegram\Http\Controllers;

use App\Domains\Telegram\Actions\UpdateBotCredentials;
use App\Domains\Telegram\Http\Requests\UpdateBotCredentialsRequest;
use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class UpdateBotCredentialsController
{
    use AuthorizesRequests;

    public function __invoke(
        TelegramConnection $connection,
        UpdateBotCredentialsRequest $request,
        UpdateBotCredentials $action,
    ): JsonResponse {
        $this->authorize('manage', $connection);

        $account = $action->handle($connection->account, $request->string('token'));

        return response()->json([
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'status' => $account->status->value,
            ],
        ]);
    }
}
