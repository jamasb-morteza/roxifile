<?php

namespace App\Domains\Files\Enums;

enum FileStatus: string
{
    case Pending = 'pending';
    case Uploaded = 'uploaded';
    case Failed = 'failed';
    case Deleting = 'deleting';
    case Deleted = 'deleted';
}
