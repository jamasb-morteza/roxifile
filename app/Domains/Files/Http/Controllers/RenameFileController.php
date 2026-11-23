<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\RenameFile;
use App\Domains\Files\Models\File;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RenameFileController
{
    use AuthorizesRequests;

    public function __invoke(Request $request, File $file, RenameFile $action): JsonResponse
    {
        $this->authorize('update', $file);

        $request->validate(['name' => ['required', 'string', 'max:255']]);

        $file = $action->handle($file, (string) $request->input('name'));

        return response()->json(['file' => ['id' => $file->id, 'name' => $file->name]]);
    }
}
