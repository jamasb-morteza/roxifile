<?php

declare(strict_types=1);

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\GenerateSpeech;
use App\Domains\Files\Http\Requests\GenerateSpeechRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TextToSpeechController
{
    public function __invoke(GenerateSpeechRequest $request, GenerateSpeech $action): Response
    {
        $requestId = (string) Str::uuid();

        if (blank(config('ai.providers.eleven.key'))) {
            Log::channel('elevenlabs_tts')->warning('ElevenLabs TTS is not configured', [
                'request_id' => $requestId,
                'provider' => 'eleven',
            ]);

            return response()->json(['message' => 'Text-to-speech provider is not configured.'], 503)
                ->header('X-Request-ID', $requestId);
        }

        try {
            $mp3 = $action->handle($request->toDto($requestId));
        } catch (Throwable) {
            return response()->json(['message' => 'Audio generation failed.'], 502)
                ->header('X-Request-ID', $requestId);
        }

        return response($mp3, 200, [
            'Content-Type' => 'audio/mpeg',
            'Content-Disposition' => 'inline; filename="speech.mp3"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Request-ID' => $requestId,
        ]);
    }
}
