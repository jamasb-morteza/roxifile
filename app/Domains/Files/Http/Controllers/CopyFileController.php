<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\CopyFile;
use App\Domains\Files\Http\Requests\CopyFileRequest;
use App\Domains\Files\Models\File;
use Illuminate\Http\JsonResponse;

class CopyFileController
{
    public function __invoke(CopyFileRequest $request, File $file, CopyFile $action): JsonResponse
    {
        $operation = $action->handle($file, $request->toDto());

        return response()->json(['operation_id' => $operation->id, 'file_id' => $operation->file_id, 'status' => $operation->status->value], 202);
    }
}
