<?php

namespace App\Domains\Telegram\Contracts;

use App\Domains\Files\Models\File;
use App\Domains\Files\Models\FileOperation;
use App\Domains\Telegram\Data\ConnectionVerification;
use App\Domains\Telegram\Data\TelegramUploadResult;
use App\Domains\Telegram\Models\TelegramConnection;
use App\Domains\Telegram\Runtime\TelegramUploadContent;

/**
 * The only surface the file manager may use to touch Telegram (spec §26).
 * Controllers and actions must never reference concrete drivers.
 */
interface TelegramStorageDriver
{
    /**
     * Upload a stream into the connection's chat.
     *
     * @param  callable(int, int): void  $onProgress  receives (bytes, total)
     */
    public function upload(FileOperation $operation, File $file, TelegramUploadContent $content, callable $onProgress): TelegramUploadResult;

    /**
     * Download the physical Telegram object of a file into $destinationPath.
     */
    public function download(File $file, string $destinationPath): void;

    /**
     * Delete the physical Telegram message backing a file.
     */
    public function delete(File $file): void;

    public function exists(File $file): ?bool;

    public function verify(TelegramConnection $connection): ConnectionVerification;
}
