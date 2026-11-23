<?php

namespace App\Domains\Files\Data;

use App\Domains\Telegram\Models\TelegramConnection;
use Illuminate\Http\UploadedFile;

/**
 * Controller→action contract for file uploads.
 */
final readonly class UploadFileData
{
    /** @param array<string, mixed>|null $protectedMetadata */
    public function __construct(
        public UploadedFile $file,
        public TelegramConnection $connection,
        public ?int $directoryId,
        public bool $encrypted = false,
        public ?string $plaintextMime = null,
        public ?array $protectedMetadata = null,
    ) {}
}
