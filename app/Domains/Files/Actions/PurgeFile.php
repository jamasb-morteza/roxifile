<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;

class PurgeFile
{
    public function __construct(private readonly DeleteFile $deleteFile) {}

    public function handle(File $file): ?FileOperation
    {
        return $this->deleteFile->handle($file, permanent: true);
    }
}
