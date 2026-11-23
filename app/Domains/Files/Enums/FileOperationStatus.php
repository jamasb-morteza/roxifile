<?php

namespace App\Domains\Files\Enums;

enum FileOperationStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Uploading = 'uploading';
    case Downloading = 'downloading';
    case Completed = 'completed';
    case Failed = 'failed';
    case Retrying = 'retrying';
    case Cancelled = 'cancelled';
    case NeedsReview = 'needs_review';
}
