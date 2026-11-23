<?php

namespace App\Domains\Telegram\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Private temporary-file storage for Phase 1 uploads/downloads (spec §21).
 *
 * Files live on the `local` (private) disk under a configured subdirectory
 * with unpredictable ULID names — never in public/, never the original
 * filename (Rules 12/13).
 */
class TelegramTempStorage
{
    public const UPLOAD_DISK = 'local';

    public function uploadPath(string $name): string
    {
        return trim((string) config('telegram.storage.upload_dir', 'telegram-upload'), '/').'/'.$name;
    }

    public function downloadPath(string $name): string
    {
        return trim((string) config('telegram.storage.download_dir', 'telegram-download'), '/').'/'.$name;
    }

    /**
     * Store an uploaded file under a fresh ULID name; returns the disk path.
     */
    public function storeUpload(UploadedFile $file): string
    {
        $path = $this->uploadPath((string) Str::ulid());

        $stream = fopen($file->getRealPath() ?: '', 'rb');

        if ($stream === false) {
            throw new \RuntimeException('Uploaded file is not readable.');
        }

        Storage::disk(self::UPLOAD_DISK)->put($path, $stream);
        fclose($stream);

        return $path;
    }

    public function exists(string $path): bool
    {
        return Storage::disk(self::UPLOAD_DISK)->exists($path);
    }

    public function delete(string $path): void
    {
        Storage::disk(self::UPLOAD_DISK)->delete($path);
    }

    public function fullPath(string $path): string
    {
        return Storage::disk(self::UPLOAD_DISK)->path($path);
    }

    /**
     * Stream content of a disk path as a resource.
     *
     * @return resource
     */
    public function readStream(string $path)
    {
        $stream = Storage::disk(self::UPLOAD_DISK)->readStream($path);

        if (! is_resource($stream)) {
            throw new \RuntimeException('Temp file '.$path.' is not readable.');
        }

        return $stream;
    }

    /**
     * Compute sha256 checksum of a stored file without loading it fully.
     */
    public function checksum(string $path): string
    {
        $stream = $this->readStream($path);
        $context = hash_init('sha256');

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 512);

                if ($chunk !== false && $chunk !== '') {
                    hash_update($context, $chunk);
                }
            }
        } finally {
            fclose($stream);
        }

        return hash_final($context);
    }

    /**
     * Sweep old orphan files lazily. The callback protects paths still referenced
     * by operations without retaining the entire operation corpus in memory.
     *
     * @param  callable(string): bool  $isReferenced
     */
    public function sweepExpired(callable $isReferenced): int
    {
        $disk = Storage::disk(self::UPLOAD_DISK);
        $cutoff = now()->subHours((int) config('telegram.storage.temp_max_age_hours', 24))->getTimestamp();
        $removed = 0;
        foreach ([$this->uploadPath(''), $this->downloadPath('')] as $prefix) {
            foreach ($disk->getDriver()->listContents($prefix, true) as $entry) {
                if (! $entry->isFile()) {
                    continue;
                }
                $path = $entry->path();
                if (($entry->lastModified() ?? $disk->lastModified($path)) <= $cutoff && ! $isReferenced($path)) {
                    $disk->delete($path);
                    $removed++;
                }
            }
        }

        return $removed;
    }
}
