<?php

namespace App\Domains\Files\Actions;

use App\Domains\Files\Data\ProtectedMetadataData;
use App\Domains\Files\Data\QueueTelegramMetadataData;
use App\Domains\Files\Models\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateProtectedMetadata
{
    public function __construct(private readonly QueueTelegramMetadata $projection) {}

    public function handle(File $file, ProtectedMetadataData $data): File
    {
        return DB::transaction(function () use ($file, $data): File {
            $file = File::query()->lockForUpdate()->findOrFail($file->id);
            if ($file->connection?->encryption_mode !== 'contents_and_metadata' || $file->encryption_header === null) {
                throw ValidationException::withMessages(['protected_metadata' => 'This file does not use protected metadata.']);
            }
            $metadata = $file->metadata ?? [];
            abort_unless(($metadata['protected_metadata'] ?? null) == $data->previousMetadata, 409, 'Metadata changed. Reload and unlock this file before editing again.');
            $metadata['protected_metadata'] = $data->metadata;
            $file->update(['metadata' => $metadata]);
            $this->projection->handle(new QueueTelegramMetadataData(
                $file->id,
                $file->status,
                $file->telegram_message_id,
            ));

            return $file;
        });
    }
}
