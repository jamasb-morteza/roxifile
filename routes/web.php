<?php

use App\Domains\Identity\Http\Controllers\GoogleAuthenticationController;
use App\Domains\Identity\Http\Controllers\UpdateLocaleController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');
Route::post('locale', UpdateLocaleController::class)->name('locale.update');

Route::middleware('guest')->prefix('auth/google')->name('auth.google.')->group(function () {
    Route::get('redirect', [GoogleAuthenticationController::class, 'redirect'])->name('redirect');
    Route::get('callback', [GoogleAuthenticationController::class, 'callback'])->name('callback');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/files.php';
