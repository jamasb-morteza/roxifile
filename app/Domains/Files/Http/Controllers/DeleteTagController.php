<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\DeleteTag;
use App\Domains\Files\Models\Tag;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class DeleteTagController
{
    use AuthorizesRequests;

    public function __invoke(Tag $tag, DeleteTag $action): JsonResponse
    {
        $this->authorize('delete', $tag);

        $action->handle($tag);

        return response()->json(['deleted' => true]);
    }
}
