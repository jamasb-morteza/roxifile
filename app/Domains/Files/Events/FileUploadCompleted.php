<?php

namespace App\Domains\Files\Events;

class FileUploadCompleted extends FileOperationEvent
{
    protected function suffix(): string
    {
        return 'completed';
    }
}
