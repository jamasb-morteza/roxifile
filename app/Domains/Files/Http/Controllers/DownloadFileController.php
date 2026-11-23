<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\DownloadFile;
use App\Domains\Files\Enums\FileOperationStatus;
use App\Domains\Files\Models\File;
use App\Domains\Telegram\Services\TelegramTempStorage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

class DownloadFileController
{
    use AuthorizesRequests;

    public function __invoke(Request $request, File $file, DownloadFile $action, TelegramTempStorage $tempStorage): BinaryFileResponse
    {
        $this->authorize('view', $file);
        $request->validate(['operation_id' => ['nullable', 'integer'], 'inline' => ['nullable', 'boolean']]);
        $operation = $action->prepared($file, $request->integer('operation_id') ?: null);
        abort_if($operation === null || $operation->status !== FileOperationStatus::Completed, 409, 'Prepare this download first.');
        abort_unless($action->isReady($operation), 410, 'This download expired. Prepare it again.');

        $path = (string) $operation->metadata['downloaded_path'];
        $response = response()->file($tempStorage->fullPath($path), [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
        ]);
        $inlineTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'application/pdf', 'audio/mpeg', 'audio/ogg', 'audio/wav', 'video/mp4', 'video/webm', 'audio/webm'];
        $canInline = $file->encryption_header === null && (in_array($file->mime_type, $inlineTypes, true)
            || ($file->mime_type === 'text/plain' && $response->getFile()->getSize() <= 1024 * 1024));
        $response->setContentDisposition($request->boolean('inline') && $canInline
            ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT, $file->name, 'download');

        return $response;
    }
}
