<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\DeleteFile;
use App\Domains\Files\Models\File;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class DeleteFileController
{
    use AuthorizesRequests;

    public function __invoke(File $file, DeleteFile $action): JsonResponse
    {
        $this->authorize('delete', $file);

        $operation = $action->handle($file);

        return response()->json([
            'file_id' => $file->id,
            'deleted' => true,
            'operation_id' => $operation?->id,
        ]);
    }
}
