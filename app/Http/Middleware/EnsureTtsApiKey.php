<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTtsApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.ai_tts.api_token');
        $actual = $request->bearerToken();

        if (! is_string($expected) || $expected === '' ||
            ! is_string($actual) || ! hash_equals($expected, $actual)) {
            return response()->json(['message' => 'Unauthorized.'], 401, [
                'WWW-Authenticate' => 'Bearer',
            ]);
        }

        return $next($request);
    }
}
