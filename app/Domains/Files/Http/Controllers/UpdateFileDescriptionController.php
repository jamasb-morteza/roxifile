<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\UpdateFileDescription;
use App\Domains\Files\Http\Requests\UpdateFileDescriptionRequest;
use App\Domains\Files\Models\File;
use Illuminate\Http\JsonResponse;

class UpdateFileDescriptionController
{
    public function __invoke(UpdateFileDescriptionRequest $request, File $file, UpdateFileDescription $action): JsonResponse
    {
        $action->handle($file, $request->toDto());

        return response()->json(['file' => ['id' => $file->id, 'description' => $file->description]]);
    }
}
