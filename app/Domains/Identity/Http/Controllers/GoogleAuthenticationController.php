<?php

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\AuthenticateWithGoogle;
use App\Domains\Identity\Data\GoogleIdentityData;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Throwable;

class GoogleAuthenticationController extends Controller
{
    public function redirect(): RedirectResponse
    {
        if (! $this->isConfigured()) {
            return to_route('login')->with('status', 'Google sign-in is not configured.');
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, AuthenticateWithGoogle $authenticateWithGoogle): RedirectResponse
    {
        if (! $this->isConfigured()) {
            return to_route('login')->with('status', 'Google sign-in is not configured.');
        }

        try {
            /** @var SocialiteUser $googleUser */
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $exception) {
            report($exception);

            return to_route('login')->with('status', 'Google sign-in could not be completed. Please try again.');
        }

        $user = $authenticateWithGoogle->handle(new GoogleIdentityData(
            id: (string) $googleUser->getId(),
            name: (string) $googleUser->getName(),
            email: (string) $googleUser->getEmail(),
            emailIsVerified: (bool) ($googleUser->user['email_verified'] ?? false),
        ));

        if ($user === null) {
            return to_route('login')->with('status', 'This Google account could not be linked. Please sign in with your existing method.');
        }

        if (filled($user->two_factor_secret) && $user->two_factor_confirmed_at !== null) {
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => false,
            ]);
            $request->session()->regenerate();

            return to_route('two-factor.login');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    private function isConfigured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }
}
