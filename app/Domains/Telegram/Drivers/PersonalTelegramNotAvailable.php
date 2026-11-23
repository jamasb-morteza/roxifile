<?php

namespace App\Domains\Telegram\Drivers;

/**
 * Placeholder exception for the future Personal Telegram method (spec §9).
 */
class PersonalTelegramNotAvailable extends \RuntimeException
{
    public static function forUpload(): self
    {
        return new self('Personal Telegram accounts are coming soon and cannot store files yet.');
    }

    public static function forDownload(): self
    {
        return new self('Personal Telegram accounts are coming soon and cannot download files yet.');
    }

    public static function forDelete(): self
    {
        return new self('Personal Telegram accounts are coming soon and cannot delete files yet.');
    }

    public static function forExists(): self
    {
        return new self('Personal Telegram accounts are coming soon and cannot be checked yet.');
    }
}
