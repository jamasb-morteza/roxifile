<?php

namespace App\Domains\Telegram\Services;

use App\Domains\Files\Models\FileOperation;
use App\Domains\Telegram\Contracts\TelegramUploadStreamTransport;
use App\Domains\Telegram\Data\SendTelegramUploadStreamData;
use App\Domains\Telegram\Runtime\TelegramUploadStreamException;
use App\Domains\Telegram\Runtime\TelegramUploadStreamToken;
use GuzzleHttp\Psr7\PumpStream;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class WorkerTelegramUploadStreamTransport implements TelegramUploadStreamTransport
{
    public function send(SendTelegramUploadStreamData $data): void
    {
        $stream = fopen($data->sourcePath, 'rb');

        if ($stream === false) {
            throw new TelegramUploadStreamException('The uploaded file is no longer readable. Upload it again.');
        }

        $size = $data->size;
        $bytesSent = 0;
        $nextOperationCheckAt = 0;
        $body = new PumpStream(static function (int $length) use ($stream, $data, &$bytesSent, &$nextOperationCheckAt): string|false|null {
            if (connection_aborted()) {
                throw new TelegramUploadStreamException('The upload client disconnected. Upload the file again.');
            }

            if ($bytesSent >= $nextOperationCheckAt) {
                $current = FileOperation::query()->select(['id', 'status', 'metadata'])->find($data->operationId);
                if ($current === null || ! empty($current->metadata['cancel_requested_at'])
                    || in_array($current->status->value, ['completed', 'cancelled', 'failed', 'needs_review'], true)) {
                    throw new TelegramUploadStreamException('The upload was cancelled or is no longer active.');
                }

                $nextOperationCheckAt = $bytesSent + 65536;
            }

            $chunk = fread($stream, $length);

            if ($chunk === false) {
                throw new TelegramUploadStreamException('The uploaded file could not be read. Upload it again.');
            }

            $bytesSent += strlen($chunk);

            return $chunk === '' && feof($stream) ? null : $chunk;
        }, ['size' => $size]);

        try {
            $response = Http::connectTimeout(3)
                ->timeout(max(60, (int) config('telegram.worker.upload_bridge_timeout_seconds', 3600)))
                ->withoutRedirecting()
                ->withHeaders([
                    'X-Roxi-Upload-Size' => (string) $size,
                    'X-Roxi-Upload-Token' => TelegramUploadStreamToken::create($data->operationId, $size),
                ])
                ->withBody($body, 'application/octet-stream')
                ->put(rtrim((string) config('telegram.worker.upload_bridge_url'), '/').'/operations/'.$data->operationId.'/content');
        } catch (ConnectionException $error) {
            throw new TelegramUploadStreamException('The Telegram worker could not receive the upload. Upload the file again.', previous: $error);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            $body->close();
        }

        if (! $response->successful()) {
            throw new TelegramUploadStreamException('The Telegram worker rejected the upload stream. Upload the file again.');
        }
    }
}
