<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $userLocale = $request->user()?->locale;
        $cookieLocale = $request->cookie('locale');

        $locale = collect([$userLocale, $cookieLocale, config('app.locale'), 'en'])
            ->first(fn (mixed $candidate): bool => in_array($candidate, ['en', 'fa'], true));

        App::setLocale($locale ?? 'en');

        return $next($request);
    }
}
