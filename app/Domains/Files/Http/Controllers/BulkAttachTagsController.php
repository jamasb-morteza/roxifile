<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\AttachTags;
use App\Domains\Files\Http\Requests\BulkAttachTagsRequest;
use Illuminate\Http\JsonResponse;

class BulkAttachTagsController
{
    public function __invoke(BulkAttachTagsRequest $request, AttachTags $action): JsonResponse
    {
        $data = $request->toDto();

        return response()->json(['updated' => $action->bulkAttach($request->user(), $data->fileIds, $data->tags)]);
    }
}
