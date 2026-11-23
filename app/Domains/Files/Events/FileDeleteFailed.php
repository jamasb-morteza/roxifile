<?php

namespace App\Domains\Files\Events;

class FileDeleteFailed extends FileOperationEvent
{
    protected function suffix(): string
    {
        return 'failed';
    }
}
