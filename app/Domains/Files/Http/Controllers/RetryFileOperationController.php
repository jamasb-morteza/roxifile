<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\ManageFileOperation;
use App\Domains\Files\Models\FileOperation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class RetryFileOperationController
{
    use AuthorizesRequests;

    public function __invoke(FileOperation $operation, ManageFileOperation $action): JsonResponse
    {
        $this->authorize('view', $operation);
        $action->retry($operation);

        return response()->json(['operation_id' => $operation->id, 'status' => $operation->status->value], 202);
    }
}
