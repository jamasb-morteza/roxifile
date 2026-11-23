<?php

namespace App\Domains\Files\Events;

class FileUploadProgress extends FileOperationEvent
{
    protected function suffix(): string
    {
        return 'progress';
    }
}
