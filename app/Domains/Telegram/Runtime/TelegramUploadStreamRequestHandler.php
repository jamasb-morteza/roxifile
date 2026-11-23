<?php

namespace App\Domains\Telegram\Runtime;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use App\Domains\Files\Enums\FileOperationStatus;
use App\Domains\Files\Enums\FileOperationType;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Telegram\Jobs\TelegramOperationJob;
use Throwable;

use function Amp\async;

class TelegramUploadStreamRequestHandler implements RequestHandler
{
    public function __construct(private readonly TelegramUploadStreamBridge $bridge) {}

    public function handleRequest(Request $request): Response
    {
        if ($request->getMethod() !== 'PUT'
            || preg_match('~^/operations/([1-9][0-9]*)/content$~D', $request->getUri()->getPath(), $matches) !== 1) {
            return new Response(HttpStatus::NOT_FOUND);
        }

        $operationId = (int) $matches[1];
        $operation = FileOperation::query()->with('file')->find($operationId);
        $size = filter_var($request->getHeader('x-roxi-upload-size'), FILTER_VALIDATE_INT);
        $contentLength = filter_var($request->getHeader('content-length'), FILTER_VALIDATE_INT);

        if (! TelegramUploadStreamToken::matches($operationId, (int) $size, $request->getHeader('x-roxi-upload-token'))) {
            return new Response(HttpStatus::UNAUTHORIZED);
        }

        if ($operation === null || $operation->operation !== FileOperationType::Upload || $operation->file === null
            || $operation->status !== FileOperationStatus::Pending
            || ($operation->metadata['upload_source'] ?? null) !== 'worker_bridge') {
            return new Response(HttpStatus::CONFLICT);
        }

        if (! is_int($size) || $size < 1 || $size !== $operation->file->size || $contentLength !== $size) {
            return new Response(HttpStatus::LENGTH_REQUIRED);
        }

        $request->getBody()->increaseSizeLimit($size);

        try {
            $session = $this->bridge->begin($operationId, $size);
        } catch (TelegramUploadStreamException) {
            return new Response(HttpStatus::CONFLICT);
        }

        $bridge = $this->bridge;
        async(static function () use ($operationId, $session, $bridge): void {
            try {
                TelegramOperationJob::dispatch($operationId);
            } catch (Throwable $error) {
                $session->fail(new TelegramUploadStreamException('The upload job could not be scheduled.', previous: $error));
                $bridge->finish($operationId);
            }
        })->ignore();

        try {
            while (($chunk = $request->getBody()->read()) !== null) {
                $offset = 0;
                $length = strlen($chunk);

                while ($offset < $length) {
                    $part = substr($chunk, $offset, TelegramUploadStreamSession::CHUNK_BYTES);
                    $session->write($part);
                    $offset += strlen($part);
                }
            }

            $session->finish();

            return new Response(HttpStatus::NO_CONTENT);
        } catch (Throwable $error) {
            $session->fail($error instanceof TelegramUploadStreamException
                ? $error
                : new TelegramUploadStreamException('The upload stream could not be received.', previous: $error));
            $bridge->finish($operationId);

            return new Response(HttpStatus::BAD_GATEWAY);
        }
    }
}
