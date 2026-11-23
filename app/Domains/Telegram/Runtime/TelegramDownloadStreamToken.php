<?php

namespace App\Domains\Telegram\Runtime;

class TelegramDownloadStreamToken
{
    public const MAX_FILE_BYTES = 512 * 1024 * 8000;

    public const TTL_SECONDS = 60;

    public static function create(int $fileId, int $userId, int $expiresAt): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new TelegramUploadStreamException('The download bridge is not configured.');
        }

        return hash_hmac('sha256', $fileId.':'.$userId.':'.$expiresAt, $key);
    }

    public static function matches(int $fileId, int $userId, int $expiresAt, ?string $token): bool
    {
        $now = time();

        return is_string($token)
            && $expiresAt >= $now
            && $expiresAt <= $now + self::TTL_SECONDS
            && hash_equals(self::create($fileId, $userId, $expiresAt), $token);
    }
}
