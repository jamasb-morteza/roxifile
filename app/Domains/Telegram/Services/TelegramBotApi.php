<?php

namespace App\Domains\Telegram\Services;

use App\Domains\Telegram\Runtime\BotApiException;
use App\Domains\Telegram\Runtime\TelegramErrors;
use App\Domains\Telegram\Runtime\TelegramUploadContent;
use GuzzleHttp\Psr7\PumpStream;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class TelegramBotApi
{
    public function __construct(private readonly AmpTelegramHttpHandler $handler) {}

    /** @param array<string, mixed> $data */
    public function call(
        #[\SensitiveParameter] string $token,
        string $method,
        array $data = [],
        ?TelegramUploadContent $uploadContent = null,
        ?string $filename = null,
        ?callable $onUploadProgress = null,
    ): Response {
        try {
            $request = Http::connectTimeout(5)->timeout($method === 'getUpdates' ? 5 : 60)->withoutRedirecting()->setHandler($this->handler);
            if ($uploadContent !== null) {
                $stream = new PumpStream(static function (int $length) use ($uploadContent, $onUploadProgress): string|false|null {
                    $chunk = $uploadContent->read($length);
                    if ($onUploadProgress !== null) {
                        $onUploadProgress($uploadContent->bytesRead(), $uploadContent->size());
                    }

                    return $chunk === '' ? null : $chunk;
                }, ['size' => $uploadContent->size()]);
                $request = $request->attach('document', $stream, $filename);
            }
            $response = $request->post('https://api.telegram.org/bot'.$token.'/'.$method, $data);
        } catch (ConnectionException) {
            throw new BotApiException('Telegram network request failed. Please try again.', true, 5);
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            $status = (int) ($response->json('error_code') ?? $response->status());
            throw new BotApiException(
                'Telegram Bot API: '.TelegramErrors::message((string) $response->json('description', 'Request rejected.')),
                $status === 429 || $status >= 500,
                $status === 429 ? max(1, (int) $response->json('parameters.retry_after', 30)) : null,
                $status,
            );
        }

        return $response;
    }

    public function download(#[\SensitiveParameter] string $token, string $remotePath, string $destination): void
    {
        if (str_contains($remotePath, '..') || preg_match('~^[A-Za-z0-9_./-]+$~', $remotePath) !== 1) {
            throw new BotApiException('Telegram returned an invalid file path.', false);
        }
        try {
            $response = Http::connectTimeout(5)->timeout(60)->withoutRedirecting()->setHandler($this->handler)->sink($destination)
                ->get('https://api.telegram.org/file/bot'.$token.'/'.$remotePath);
        } catch (ConnectionException) {
            throw new BotApiException('Telegram network download failed. Please try again.', true, 5);
        }
        if (! $response->successful()) {
            throw new BotApiException('Telegram file download failed.', $response->status() === 429 || $response->status() >= 500,
                $response->status() === 429 ? max(1, (int) ($response->json('parameters.retry_after') ?? $response->header('Retry-After') ?: 30)) : null, $response->status());
        }
    }
}
