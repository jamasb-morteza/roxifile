<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\MoveFile;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BulkMoveFilesController
{
    use AuthorizesRequests;

    public function __invoke(Request $request, MoveFile $action): JsonResponse
    {
        $request->validate([
            'connection_id' => ['prohibited'],
            'telegram_connection_id' => ['prohibited'],
            'file_ids' => ['required', 'array', 'max:100'],
            'file_ids.*' => ['integer'],
            'directory_id' => ['nullable', 'integer'],
        ]);

        $moved = $action->bulk(
            $request->user(),
            array_values(array_map(intval(...), (array) $request->input('file_ids'))),
            $request->input('directory_id') ? $request->integer('directory_id') : null,
        );

        return response()->json(['moved' => $moved]);
    }
}
