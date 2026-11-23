<?php

namespace App\Domains\Telegram\Http\Controllers;

use App\Domains\Telegram\Actions\AuthenticateRoxiTelegramAccount;
use App\Domains\Telegram\Enums\RoxiLoginStep;
use App\Domains\Telegram\Http\Requests\CompleteRoxiLoginCodeRequest;
use App\Domains\Telegram\Http\Requests\CompleteRoxiLoginPasswordRequest;
use App\Domains\Telegram\Http\Requests\StartRoxiLoginRequest;
use App\Domains\Telegram\Runtime\RoxiLoginException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RoxiLoginController
{
    public function confirmAccess(): RedirectResponse
    {
        return to_route('telegram.settings')->with('roxi_login_open', true);
    }

    public function start(
        StartRoxiLoginRequest $request,
        AuthenticateRoxiTelegramAccount $action,
    ): JsonResponse {
        return $this->respond(fn (): RoxiLoginStep => $action->start($request->toDto(), $request->session()), 202);
    }

    public function completeCode(
        CompleteRoxiLoginCodeRequest $request,
        AuthenticateRoxiTelegramAccount $action,
    ): JsonResponse {
        return $this->respond(fn (): RoxiLoginStep => $action->completeCode($request->toDto(), $request->session()));
    }

    public function completePassword(
        CompleteRoxiLoginPasswordRequest $request,
        AuthenticateRoxiTelegramAccount $action,
    ): JsonResponse {
        return $this->respond(fn (): RoxiLoginStep => $action->completePassword($request->toDto(), $request->session()));
    }

    public function cancel(Request $request, AuthenticateRoxiTelegramAccount $action): JsonResponse
    {
        $action->cancel($request->session());

        return response()->json(['step' => RoxiLoginStep::AwaitingPhone->value]);
    }

    /**
     * @param  callable(): RoxiLoginStep  $operation
     */
    private function respond(callable $operation, int $status = 200): JsonResponse
    {
        try {
            $step = $operation();
        } catch (RoxiLoginException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status);
        }

        return response()->json(['step' => $step->value], $status);
    }
}
