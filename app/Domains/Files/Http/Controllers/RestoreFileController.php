<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\RestoreFile;
use App\Domains\Files\Models\File;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class RestoreFileController
{
    use AuthorizesRequests;

    public function __invoke(File $file, RestoreFile $action): JsonResponse
    {
        $this->authorize('delete', $file);

        $action->handle($file);

        return response()->json([
            'file_id' => $file->id,
            'restored' => true,
        ]);
    }
}
