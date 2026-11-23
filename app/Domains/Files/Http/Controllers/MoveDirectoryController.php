<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\MoveDirectory;
use App\Domains\Files\Models\Directory;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MoveDirectoryController
{
    use AuthorizesRequests;

    public function __invoke(Request $request, Directory $directory, MoveDirectory $action): JsonResponse
    {
        $this->authorize('update', $directory);

        $directory = $action->handle(
            $directory,
            $request->input('parent_id') ? $request->integer('parent_id') : null,
        );

        return response()->json([
            'directory' => ['id' => $directory->id, 'name' => $directory->name, 'path' => $directory->path],
        ]);
    }
}
