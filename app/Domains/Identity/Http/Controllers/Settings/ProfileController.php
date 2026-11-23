<?php

namespace App\Domains\Identity\Http\Controllers\Settings;

use App\Domains\Identity\Actions\DeleteUserProfile;
use App\Domains\Identity\Actions\UpdateUserProfile;
use App\Domains\Identity\Http\Requests\Settings\ProfileDeleteRequest;
use App\Domains\Identity\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, UpdateUserProfile $updateUserProfile): RedirectResponse
    {
        $updateUserProfile->handle($request->user(), $request->toDto());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request, DeleteUserProfile $deleteUserProfile): RedirectResponse
    {
        $completed = $deleteUserProfile->handle($request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => $completed
            ? __('Account deleted.') : __('Account deletion requested. Access has ended; Telegram cleanup continues before account records are removed.')]);

        return redirect('/');
    }
}
