<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Models\Directory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListDirectoriesController
{
    public function __invoke(Request $request): JsonResponse
    {
        $directories = Directory::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('path')
            ->get(['id', 'parent_id', 'name', 'path']);

        return response()->json([
            'directories' => $directories->map(fn (Directory $d): array => [
                'id' => $d->id,
                'parent_id' => $d->parent_id,
                'name' => $d->name,
                'path' => $d->path,
            ])->all(),
        ]);
    }
}
