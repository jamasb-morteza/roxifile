<?php

use App\Domains\Identity\Http\Controllers\Settings\ProfileController;
use App\Domains\Identity\Http\Controllers\Settings\SecurityController;
use App\Domains\Telegram\Http\Controllers\RoxiLoginController;
use App\Domains\Telegram\Http\Controllers\TelegramSettingsController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('settings/telegram', TelegramSettingsController::class)->name('telegram.settings');

    Route::get('settings/telegram/roxi-login/confirm', [RoxiLoginController::class, 'confirmAccess'])
        ->middleware(['can:start-telegram-worker', RequirePassword::class])
        ->name('telegram.roxi.confirm');

    Route::prefix('settings/telegram/roxi-login')->middleware([
        'can:start-telegram-worker',
        RequirePassword::class,
        'throttle:6,1',
    ])->name('telegram.roxi.login.')->group(function () {
        Route::post('phone', [RoxiLoginController::class, 'start'])->name('phone');
        Route::post('code', [RoxiLoginController::class, 'completeCode'])->name('code');
        Route::post('password', [RoxiLoginController::class, 'completePassword'])->name('password');
        Route::post('cancel', [RoxiLoginController::class, 'cancel'])->name('cancel');
    });
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
