<?php

namespace App\Domains\Files\Enums;

enum FileOperationType: string
{
    case Upload = 'upload';
    case Download = 'download';
    case Delete = 'delete';
    case Copy = 'copy';
    case Metadata = 'metadata';
    case Reconcile = 'reconcile';
}
