<?php

namespace App\Domains\Files\Events;

class FileDeleteStarted extends FileOperationEvent
{
    protected function suffix(): string
    {
        return 'started';
    }
}
