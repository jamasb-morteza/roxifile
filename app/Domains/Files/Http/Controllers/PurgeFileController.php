<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\PurgeFile;
use App\Domains\Files\Enums\FileOperationStatus;
use App\Domains\Files\Models\File;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class PurgeFileController
{
    use AuthorizesRequests;

    public function __invoke(File $file, PurgeFile $action): JsonResponse
    {
        $this->authorize('delete', $file);

        $operation = $action->handle($file);
        $purged = $operation === null || $operation->status === FileOperationStatus::Completed;

        return response()->json([
            'file_id' => $file->id,
            'purged' => $purged,
            'operation_id' => $operation?->id,
        ], $purged ? 200 : 202);
    }
}
