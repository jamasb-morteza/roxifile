<?php

namespace App\Domains\Files\Events;

class FileDeleteCompleted extends FileOperationEvent
{
    protected function suffix(): string
    {
        return 'completed';
    }
}
