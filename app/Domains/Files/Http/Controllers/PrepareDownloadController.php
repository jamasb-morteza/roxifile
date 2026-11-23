<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\DownloadFile;
use App\Domains\Files\Models\File;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class PrepareDownloadController
{
    use AuthorizesRequests;

    public function __invoke(File $file, DownloadFile $action): JsonResponse
    {
        $this->authorize('view', $file);
        $operation = $action->handle($file);

        return response()->json([
            'operation_id' => $operation->id,
            'file_id' => $file->id,
            'status' => $operation->status->value,
        ], 202);
    }
}
