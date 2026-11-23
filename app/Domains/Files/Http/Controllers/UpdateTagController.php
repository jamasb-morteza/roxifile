<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\RenameTag;
use App\Domains\Files\Models\Tag;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UpdateTagController
{
    use AuthorizesRequests;

    public function __invoke(Request $request, Tag $tag, RenameTag $action): JsonResponse
    {
        $this->authorize('update', $tag);

        $request->validate(['name' => ['required', 'string', 'max:32']]);

        $tag = $action->handle($tag, (string) $request->input('name'));

        return response()->json(['tag' => ['id' => $tag->id, 'name' => $tag->name]]);
    }
}
