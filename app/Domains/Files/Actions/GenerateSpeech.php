<?php

declare(strict_types=1);

namespace App\Domains\Files\Actions;

use App\Domains\Files\Data\GenerateSpeechData;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Audio;
use RuntimeException;
use Throwable;

class GenerateSpeech
{
    public function handle(GenerateSpeechData $data): string
    {
        $model = (string) config('services.ai_tts.model', 'eleven_v3');
        $context = [
            'request_id' => $data->requestId,
            'provider' => 'eleven',
            'model' => $model,
            'voice' => $data->voice,
            'text_length' => mb_strlen($data->text),
        ];

        $startedAt = microtime(true);
        Log::channel('elevenlabs_tts')->info('ElevenLabs TTS started', $context);

        try {
            $response = Audio::of($data->text)
                ->voice($data->voice)
                ->timeout(60)
                ->generate('eleven', $model);

            $bytes = $response->content();

            if ($bytes === '' || ($response->mimeType() !== null && $response->mimeType() !== 'audio/mpeg')) {
                throw new RuntimeException('ElevenLabs returned invalid audio.');
            }
        } catch (Throwable $exception) {
            $httpStatus = null;
            for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof RequestException) {
                    $httpStatus = $cause->response->status();
                    break;
                }
            }

            Log::channel('elevenlabs_tts')->error('ElevenLabs TTS failed', [
                ...$context,
                'duration_ms' => round((microtime(true) - $startedAt) * 1000),
                'error_type' => $exception::class,
                'upstream_http_status' => $httpStatus,
            ]);

            throw $exception;
        }

        Log::channel('elevenlabs_tts')->info('ElevenLabs TTS completed', [
            ...$context,
            'duration_ms' => round((microtime(true) - $startedAt) * 1000),
            'audio_bytes' => strlen($bytes),
            'mime_type' => 'audio/mpeg',
        ]);

        return $bytes;
    }
}
