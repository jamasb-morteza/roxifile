<?php

namespace App\Domains\Files\Data;

final readonly class BulkTagFilesData
{
    /** @param list<int> $fileIds
     * @param  list<string>  $tags
     */
    public function __construct(public array $fileIds, public array $tags) {}
}
