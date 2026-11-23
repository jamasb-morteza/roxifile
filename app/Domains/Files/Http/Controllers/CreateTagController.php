<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\CreateTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreateTagController
{
    public function __invoke(Request $request, CreateTag $action): JsonResponse
    {
        $request->validate(['name' => ['required', 'string', 'max:32']]);

        $tag = $action->handle($request->user(), (string) $request->input('name'));

        return response()->json(['tag' => ['id' => $tag->id, 'name' => $tag->name]], 201);
    }
}
