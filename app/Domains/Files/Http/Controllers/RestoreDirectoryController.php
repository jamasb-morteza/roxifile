<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\RestoreDirectory;
use App\Domains\Files\Models\Directory;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class RestoreDirectoryController
{
    use AuthorizesRequests;

    public function __invoke(Directory $directory, RestoreDirectory $action): JsonResponse
    {
        $this->authorize('delete', $directory);

        $action->handle($directory);

        return response()->json(['restored' => true]);
    }
}
