<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\ListFileOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListFileOperationsController
{
    public function __invoke(Request $request, ListFileOperations $action): JsonResponse
    {
        $data = $request->validate(['ids' => ['nullable', 'array', 'max:20'], 'ids.*' => ['integer', 'min:1', 'distinct']]);
        $ids = array_values(array_map(intval(...), $data['ids'] ?? []));

        return response()->json(['operations' => $action->handle($request->user(), $ids)]);
    }
}
