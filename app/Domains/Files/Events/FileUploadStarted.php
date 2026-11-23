<?php

namespace App\Domains\Files\Events;

class FileUploadStarted extends FileOperationEvent
{
    protected function suffix(): string
    {
        return 'started';
    }
}
