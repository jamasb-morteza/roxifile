<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\AttachTags;
use App\Domains\Files\Models\File;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttachTagsController
{
    use AuthorizesRequests;

    public function __invoke(Request $request, File $file, AttachTags $action): JsonResponse
    {
        $this->authorize('update', $file);

        $request->validate(['tags' => ['required', 'array', 'max:20'], 'tags.*' => ['string', 'max:32']]);

        $file = $action->handle($request->user(), $file, array_values(array_map(strval(...), (array) $request->input('tags'))));

        return response()->json([
            'file' => ['id' => $file->id],
            'tags' => $file->tags->map(fn ($t): array => ['id' => $t->id, 'name' => $t->name])->all(),
        ]);
    }
}
