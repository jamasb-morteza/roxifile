<?php

namespace App\Domains\Telegram\Runtime;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use App\Domains\Files\Enums\FileStatus;
use App\Domains\Files\Models\File;
use App\Domains\Telegram\Contracts\TelegramDownloadStreamDriver;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\StorageManager;
use Throwable;

class TelegramDownloadStreamRequestHandler implements RequestHandler
{
    public function __construct(private readonly StorageManager $storageManager) {}

    public function handleRequest(Request $request): Response
    {
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true)
            || preg_match('~^/files/([1-9][0-9]*)/content$~D', $request->getUri()->getPath(), $matches) !== 1) {
            return new Response(HttpStatus::NOT_FOUND);
        }

        $fileId = (int) $matches[1];
        $userId = filter_var($request->getHeader('x-roxi-download-user'), FILTER_VALIDATE_INT);
        $expiresAt = filter_var($request->getHeader('x-roxi-download-expires'), FILTER_VALIDATE_INT);

        if (! is_int($userId) || ! is_int($expiresAt)
            || ! TelegramDownloadStreamToken::matches(
                $fileId,
                $userId,
                $expiresAt,
                $request->getHeader('x-roxi-download-capability'),
            )) {
            return new Response(HttpStatus::UNAUTHORIZED);
        }

        $file = File::query()->with('connection')->where('user_id', $userId)->find($fileId);
        if ($file === null || $file->status !== FileStatus::Uploaded) {
            return new Response(HttpStatus::NOT_FOUND);
        }

        if ($file->size < 1 || $file->size > TelegramDownloadStreamToken::MAX_FILE_BYTES) {
            return new Response(HttpStatus::PAYLOAD_TOO_LARGE);
        }

        if (! in_array($file->connection?->method, [TelegramMethod::Roxi, TelegramMethod::Invite], true)) {
            return new Response(HttpStatus::NOT_IMPLEMENTED);
        }

        $driver = $this->storageManager->for($file->connection);
        if (! $driver instanceof TelegramDownloadStreamDriver) {
            return new Response(HttpStatus::NOT_IMPLEMENTED);
        }

        $range = $this->normalizeRange($request->getHeader('range'), $file->size);
        if ($range instanceof Response) {
            return $range;
        }
        if ($range !== null) {
            $request = clone $request;
            $request->setHeader('range', $range);
        }

        try {
            return $driver->streamDownloadResponse($file, $request);
        } catch (Throwable) {
            return new Response(HttpStatus::BAD_GATEWAY);
        }
    }

    private function normalizeRange(?string $range, int $size): string|Response|null
    {
        if ($range === null || $range === '') {
            return null;
        }

        if (str_contains($range, ',')) {
            return new Response(HttpStatus::NOT_IMPLEMENTED);
        }

        if (preg_match('/^bytes=(\d*)-(\d*)$/i', trim($range), $matches) !== 1
            || ($matches[1] === '' && $matches[2] === '')) {
            return new Response(HttpStatus::RANGE_NOT_SATISFIABLE, ['Content-Range' => 'bytes */'.$size]);
        }

        if ($matches[1] === '') {
            $suffixBytes = (int) $matches[2];
            if ($suffixBytes < 1) {
                return new Response(HttpStatus::RANGE_NOT_SATISFIABLE, ['Content-Range' => 'bytes */'.$size]);
            }

            $start = max(0, $size - $suffixBytes);
            $end = $size - 1;
        } else {
            $start = (int) $matches[1];
            $end = $matches[2] === '' ? $size - 1 : (int) $matches[2];
            if ($start >= $size || $end < $start) {
                return new Response(HttpStatus::RANGE_NOT_SATISFIABLE, ['Content-Range' => 'bytes */'.$size]);
            }

            $end = min($end, $size - 1);
        }

        return 'bytes='.$start.'-'.$end;
    }
}
