<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\PurgeDirectory;
use App\Domains\Files\Models\Directory;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class PurgeDirectoryController
{
    use AuthorizesRequests;

    public function __invoke(Directory $directory, PurgeDirectory $action): JsonResponse
    {
        $this->authorize('delete', $directory);

        $purged = $action->handle($directory);

        return response()->json(['purged' => $purged], $purged ? 200 : 202);
    }
}
