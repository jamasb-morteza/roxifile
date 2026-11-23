<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\DeleteFile;
use App\Domains\Files\Models\File;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BulkDeleteFilesController
{
    use AuthorizesRequests;

    public function __invoke(Request $request, DeleteFile $action): JsonResponse
    {
        $request->validate([
            'file_ids' => ['required', 'array', 'max:100'],
            'file_ids.*' => ['integer'],
        ]);

        $deleted = 0;

        File::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('id', (array) $request->input('file_ids'))
            ->each(function (File $file) use ($action, &$deleted): void {
                $this->authorize('delete', $file);
                $action->handle($file);
                $deleted++;
            });

        return response()->json(['deleted' => $deleted]);
    }
}
