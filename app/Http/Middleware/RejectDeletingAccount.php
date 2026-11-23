<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RejectDeletingAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->deletion_requested_at !== null) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'Account deletion is in progress. Access is disabled while Telegram cleanup completes.');
        }

        return $next($request);
    }
}
