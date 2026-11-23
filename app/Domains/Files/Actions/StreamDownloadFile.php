<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Enums\FileStatus;
use App\Domains\Files\Models\File;
use App\Domains\Telegram\Actions\StreamTelegramFileDownload;
use App\Domains\Telegram\Data\StreamTelegramFileDownloadData;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Runtime\TelegramDownloadStreamToken;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StreamDownloadFile
{
    public function __construct(private readonly StreamTelegramFileDownload $streamTelegramFileDownload) {}

    public function handle(File $file, Request $request): Response
    {
        abort_if($file->status !== FileStatus::Uploaded, 409, 'This file is no longer available.');

        if (! in_array($file->connection?->method, [TelegramMethod::Roxi, TelegramMethod::Invite], true)) {
            return response()->json([
                'message' => 'Streaming supports RoxiFile MTProto connections. Use the prepared download for this connection.',
            ], 501);
        }

        if ($file->size < 1 || $file->size > TelegramDownloadStreamToken::MAX_FILE_BYTES) {
            return response()->json([
                'message' => 'MTProto streaming supports files up to 4,194,304,000 bytes. This file is outside that limit.',
            ], 413);
        }

        $range = $request->headers->has('Range') && ! $request->headers->has('If-Range')
            ? (string) $request->header('Range')
            : null;

        return $this->streamTelegramFileDownload->handle(new StreamTelegramFileDownloadData(
            fileId: $file->id,
            ownerId: $file->user_id,
            head: $request->isMethod('HEAD'),
            range: $range,
        ));
    }
}
