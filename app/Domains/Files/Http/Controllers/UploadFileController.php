<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\UploadFile;
use App\Domains\Files\Data\UploadFileData;
use App\Domains\Files\Http\Requests\StoreFileRequest;
use Illuminate\Http\JsonResponse;

class UploadFileController
{
    public function __invoke(
        StoreFileRequest $request,
        UploadFile $action,
    ): JsonResponse {
        $connection = $request->resolvedConnection();
        $directory = $request->resolvedDirectory();

        abort_unless($connection !== null, 422, 'No valid Telegram connection was provided.');

        $operation = $action->handle(
            $request->user(),
            new UploadFileData(
                file: $request->file('file'),
                connection: $connection,
                directoryId: $directory?->id,
                encrypted: $request->boolean('encrypted'),
                plaintextMime: $request->validated('plaintext_mime'),
                protectedMetadata: $request->protectedMetadata(),
            ),
        );

        return response()->json([
            'operation_id' => $operation->id,
            'file_id' => $operation->file_id,
            'status' => $operation->status->value,
        ], 202);
    }
}
