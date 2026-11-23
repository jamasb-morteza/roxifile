<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\DetachTag;
use App\Domains\Files\Models\File;
use App\Domains\Files\Models\Tag;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class DetachTagController
{
    use AuthorizesRequests;

    public function __invoke(File $file, Tag $tag, DetachTag $action): JsonResponse
    {
        $this->authorize('update', $file);

        $action->handle($file, $tag);

        return response()->json(['detached' => true]);
    }
}
