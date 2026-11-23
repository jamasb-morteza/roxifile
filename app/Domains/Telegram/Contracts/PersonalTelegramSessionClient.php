<?php

namespace App\Domains\Telegram\Contracts;

use App\Domains\Telegram\Data\PersonalTelegramUploadData;
use App\Domains\Telegram\Data\TelegramUploadResult;
use App\Domains\Telegram\Enums\PersonalTelegramLoginOutcome;
use App\Domains\Telegram\Runtime\TelegramUploadContent;

interface PersonalTelegramSessionClient
{
    public function requestCode(string $sessionName, string $phone): void;

    public function completeCode(string $sessionName, string $code): PersonalTelegramLoginOutcome;

    public function completePassword(string $sessionName, string $password): void;

    public function revoke(string $sessionName): void;

    /**
     * @param  callable(int, int): void  $onProgress
     */
    public function upload(string $sessionName, PersonalTelegramUploadData $data, TelegramUploadContent $content, callable $onProgress): TelegramUploadResult;

    public function download(string $sessionName, int $messageId, string $destinationPath): void;

    public function delete(string $sessionName, int $messageId): void;

    public function exists(string $sessionName, int $messageId): bool;

    public function verify(string $sessionName): void;
}
