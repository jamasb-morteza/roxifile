<?php

namespace App\Domains\Telegram\Services;

use App\Domains\Telegram\Contracts\TelegramDownloadStreamTransport;
use App\Domains\Telegram\Data\StreamTelegramFileDownloadData;
use App\Domains\Telegram\Runtime\TelegramDownloadStreamToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class WorkerTelegramDownloadStreamTransport implements TelegramDownloadStreamTransport
{
    public function stream(StreamTelegramFileDownloadData $data): Response
    {
        $expiresAt = time() + TelegramDownloadStreamToken::TTL_SECONDS;

        try {
            $workerRequest = Http::connectTimeout(3)
                ->timeout(max(60, (int) config('telegram.worker.upload_bridge_timeout_seconds', 3600)))
                ->withoutRedirecting()
                ->withOptions(['stream' => true])
                ->withHeaders([
                    'X-Roxi-Download-Expires' => (string) $expiresAt,
                    'X-Roxi-Download-User' => (string) $data->ownerId,
                    'X-Roxi-Download-Capability' => TelegramDownloadStreamToken::create($data->fileId, $data->ownerId, $expiresAt),
                ]);

            if ($data->range !== null) {
                $workerRequest = $workerRequest->withHeader('Range', $data->range);
            }

            $workerResponse = $data->head
                ? $workerRequest->head(rtrim((string) config('telegram.worker.upload_bridge_url'), '/').'/files/'.$data->fileId.'/content')
                : $workerRequest->get(rtrim((string) config('telegram.worker.upload_bridge_url'), '/').'/files/'.$data->fileId.'/content');
        } catch (ConnectionException) {
            return response()->json(['message' => 'The Telegram worker is unavailable. Retry the download.'], 502);
        }

        $status = $workerResponse->status();

        if ($status === 501) {
            return response()->json(['message' => 'This Telegram method requires a prepared download.'], 501);
        }

        if ($status >= 500 || in_array($status, [401, 403], true)) {
            return response()->json(['message' => 'The Telegram worker could not stream this file. Retry the download.'], 502);
        }

        $body = $workerResponse->toPsrResponse()->getBody();
        $headers = [
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'X-Accel-Buffering' => 'no',
            'X-Content-Type-Options' => 'nosniff',
        ];

        foreach (['Accept-Ranges', 'Content-Disposition', 'Content-Length', 'Content-Range', 'Content-Type'] as $header) {
            $value = $workerResponse->header($header);
            if (is_string($value) && $value !== '') {
                $headers[$header] = $value;
            }
        }

        return response()->stream(static function () use ($body): void {
            $previousIgnoreUserAbort = ignore_user_abort(true);

            try {
                while (! $body->eof() && ! connection_aborted()) {
                    $chunk = $body->read(65536);
                    if ($chunk === '') {
                        break;
                    }

                    echo $chunk;
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            } finally {
                $body->close();
                ignore_user_abort($previousIgnoreUserAbort);
            }
        }, $status, $headers);
    }
}
