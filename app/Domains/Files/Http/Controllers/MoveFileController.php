<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\MoveFile;
use App\Domains\Files\Models\File;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MoveFileController
{
    use AuthorizesRequests;

    public function __invoke(Request $request, File $file, MoveFile $action): JsonResponse
    {
        $this->authorize('update', $file);
        $request->validate(['connection_id' => ['prohibited'], 'telegram_connection_id' => ['prohibited']]);

        $file = $action->handle(
            $file,
            $request->input('directory_id') ? $request->integer('directory_id') : null,
        );

        return response()->json(['file' => ['id' => $file->id, 'directory_id' => $file->directory_id]]);
    }
}
