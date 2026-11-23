<?php

namespace App\Domains\Identity\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    public function toResponse($request): Response
    {
        return $request->wantsJson()
            ? new JsonResponse(['two_factor' => true], 200)
            : redirect()->intended($this->redirectPath());
    }

    private function redirectPath(): string
    {
        return Fortify::redirects('login', config('fortify.home'));
    }
}
