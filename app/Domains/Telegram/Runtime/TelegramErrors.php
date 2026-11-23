<?php

namespace App\Domains\Telegram\Runtime;

class TelegramErrors
{
    public static function message(string $message): string
    {
        $message = preg_replace([
            '~(?:https?://)?api\.telegram\.org/[^\s]+~i',
            '~\b(?:bot)?\d{3,20}:[A-Za-z0-9_-]+~i',
            '~\b(?:ROXI|BOT)-[A-Z0-9]{4}-[A-Z0-9]{4}\b~i',
            '~/(?:var/www|root|tmp|storage)/[^\s]+~',
            '~\b[a-f0-9]{32,}\b~i',
            '~\b(?:auth_key|session|password|api_hash|token)\s*[=:]\s*[^\s,;]+~i',
        ], '[redacted]', $message) ?? 'Telegram operation failed.';

        return mb_substr($message, 0, 500);
    }
}
