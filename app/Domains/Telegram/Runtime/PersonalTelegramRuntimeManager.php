<?php

namespace App\Domains\Telegram\Runtime;

use App\Domains\Telegram\Contracts\PersonalTelegramSessionClient;
use App\Domains\Telegram\Data\PersonalTelegramUploadData;
use App\Domains\Telegram\Data\TelegramUploadResult;
use App\Domains\Telegram\Enums\PersonalTelegramLoginOutcome;
use App\Domains\Telegram\Enums\TelegramMethod;
use App\Domains\Telegram\Models\TelegramAccount;
use Illuminate\Support\Facades\File;

class PersonalTelegramRuntimeManager
{
    public function __construct(private readonly PersonalTelegramSessionClient $sessions) {}

    public function requestCode(TelegramAccount $account, string $phone): void
    {
        $this->sessions->requestCode($this->sessionNameFor($account), $phone);
    }

    public function completeCode(TelegramAccount $account, string $code): PersonalTelegramLoginOutcome
    {
        return $this->sessions->completeCode($this->sessionNameFor($account), $code);
    }

    public function completePassword(TelegramAccount $account, string $password): void
    {
        $this->sessions->completePassword($this->sessionNameFor($account), $password);
    }

    public function revoke(TelegramAccount $account): void
    {
        $this->sessions->revoke($this->sessionNameFor($account));
    }

    /**
     * @param  callable(int, int): void  $onProgress
     */
    public function upload(TelegramAccount $account, PersonalTelegramUploadData $data, TelegramUploadContent $content, callable $onProgress): TelegramUploadResult
    {
        return $this->sessions->upload($this->sessionNameFor($account), $data, $content, $onProgress);
    }

    public function download(TelegramAccount $account, int $messageId, string $destinationPath): void
    {
        $this->sessions->download($this->sessionNameFor($account), $messageId, $destinationPath);
    }

    public function delete(TelegramAccount $account, int $messageId): void
    {
        $this->sessions->delete($this->sessionNameFor($account), $messageId);
    }

    public function exists(TelegramAccount $account, int $messageId): bool
    {
        return $this->sessions->exists($this->sessionNameFor($account), $messageId);
    }

    public function verify(TelegramAccount $account): void
    {
        $this->sessions->verify($this->sessionNameFor($account));
    }

    public function sessionNameFor(TelegramAccount $account): string
    {
        if ($account->method !== TelegramMethod::Personal || $account->is_system || $account->user_id === null) {
            throw new \InvalidArgumentException('A personal Telegram account must have an owner.');
        }

        $directory = storage_path('app/private/madeline/personal');
        File::ensureDirectoryExists($directory, 0700);

        $key = (string) config('app.key');
        $identity = $account->user_id.':'.$account->getKey();

        return $directory.'/'.hash_hmac('sha256', $identity, $key);
    }
}
