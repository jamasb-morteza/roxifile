<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\UpdateProtectedMetadata;
use App\Domains\Files\Http\Requests\UpdateProtectedMetadataRequest;
use App\Domains\Files\Models\File;
use Illuminate\Http\JsonResponse;

class UpdateProtectedMetadataController
{
    public function __invoke(UpdateProtectedMetadataRequest $request, File $file, UpdateProtectedMetadata $action): JsonResponse
    {
        $action->handle($file, $request->toDto());

        return response()->json(['file_id' => $file->id]);
    }
}
