<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\RenameDirectory;
use App\Domains\Files\Models\Directory;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RenameDirectoryController
{
    use AuthorizesRequests;

    public function __invoke(Request $request, Directory $directory, RenameDirectory $action): JsonResponse
    {
        $this->authorize('update', $directory);

        $request->validate(['name' => ['required', 'string', 'max:128']]);

        $directory = $action->handle($directory, (string) $request->input('name'));

        return response()->json([
            'directory' => ['id' => $directory->id, 'name' => $directory->name, 'path' => $directory->path],
        ]);
    }
}
