<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\StreamDownloadFile;
use App\Domains\Files\Models\File;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StreamDownloadFileController
{
    use AuthorizesRequests;

    public function __invoke(File $file, Request $request, StreamDownloadFile $action): Response
    {
        $this->authorize('view', $file);

        return $action->handle($file, $request);
    }
}
