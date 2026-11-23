<?php

namespace App\Domains\Files\Data;

/**
 * Controller→action contract for the files listing (search + filters).
 */
final readonly class SearchFilesData
{
    /**
     * @param  list<int>  $tagIds
     */
    public function __construct(
        public ?string $q = null,
        public ?string $fileType = null,
        public ?string $mimeType = null,
        public array $tagIds = [],
        public ?int $telegramAccountId = null,
        public ?int $telegramConnectionId = null,
        public ?string $createdAfter = null,
        public ?string $createdBefore = null,
        public ?int $directoryId = null,
        public ?string $sort = null,
        public ?string $direction = null,
        public int $perPage = 50,
        public bool $trash = false,
        public bool $global = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toFilters(): array
    {
        return [
            'q' => $this->q,
            'file_type' => $this->fileType,
            'mime_type' => $this->mimeType,
            'tag_ids' => $this->tagIds,
            'telegram_account_id' => $this->telegramAccountId,
            'telegram_connection_id' => $this->telegramConnectionId,
            'created_after' => $this->createdAfter,
            'created_before' => $this->createdBefore,
            'directory_id' => $this->directoryId,
            'sort' => $this->sort,
            'direction' => $this->direction,
            'per_page' => $this->perPage,
            'trash' => $this->trash,
            'global' => $this->global,
        ];
    }
}
