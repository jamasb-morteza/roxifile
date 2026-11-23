<?php

namespace App\Domains\Files\Events;

class FileUploadFailed extends FileOperationEvent
{
    protected function suffix(): string
    {
        return 'failed';
    }
}
