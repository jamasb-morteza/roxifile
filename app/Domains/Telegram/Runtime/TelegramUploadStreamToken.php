<?php

namespace App\Domains\Telegram\Runtime;

class TelegramUploadStreamToken
{
    public static function create(int $operationId, int $size): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new TelegramUploadStreamException('The upload bridge is not configured.');
        }

        return hash_hmac('sha256', $operationId.':'.$size, $key);
    }

    public static function matches(int $operationId, int $size, ?string $token): bool
    {
        return is_string($token) && hash_equals(self::create($operationId, $size), $token);
    }
}
