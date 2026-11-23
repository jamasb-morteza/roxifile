<?php

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\UpdateUserLocale;
use App\Domains\Identity\Http\Requests\UpdateLocaleRequest;
use Illuminate\Http\RedirectResponse;

class UpdateLocaleController
{
    public function __invoke(UpdateLocaleRequest $request, UpdateUserLocale $updateUserLocale): RedirectResponse
    {
        $data = $request->toDto();

        if ($user = $request->user()) {
            $updateUserLocale->handle($user, $data);
        }

        return back()->withCookie(cookie(
            'locale',
            $data->locale,
            60 * 24 * 365,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax',
        ));
    }
}
