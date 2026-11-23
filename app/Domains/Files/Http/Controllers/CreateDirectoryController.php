<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\CreateDirectory;
use App\Domains\Files\Data\CreateDirectoryData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreateDirectoryController
{
    public function __invoke(Request $request, CreateDirectory $action): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        $directory = $action->handle(
            $request->user(),
            new CreateDirectoryData(
                name: (string) $data['name'],
                parentId: $data['parent_id'] ?? null,
            ),
        );

        return response()->json([
            'directory' => [
                'id' => $directory->id,
                'name' => $directory->name,
                'path' => $directory->path,
            ],
        ], 201);
    }
}
