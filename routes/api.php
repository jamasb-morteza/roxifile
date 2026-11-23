<?php

use App\Domains\Files\Http\Controllers\TextToSpeechController;
use App\Http\Middleware\EnsureTtsApiKey;
use Illuminate\Support\Facades\Route;

Route::post('v1/ai/text-to-speech', TextToSpeechController::class)
    ->middleware([EnsureTtsApiKey::class, 'throttle:20,1'])
    ->name('api.v1.ai.text-to-speech');
