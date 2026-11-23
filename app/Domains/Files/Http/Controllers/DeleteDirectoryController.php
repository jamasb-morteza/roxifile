<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\DeleteDirectory;
use App\Domains\Files\Models\Directory;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class DeleteDirectoryController
{
    use AuthorizesRequests;

    public function __invoke(Directory $directory, DeleteDirectory $action): JsonResponse
    {
        $this->authorize('delete', $directory);

        $action->handle($directory);

        return response()->json(['deleted' => true]);
    }
}
