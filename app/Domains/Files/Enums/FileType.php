<?php

namespace App\Domains\Files\Enums;

enum FileType: string
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Pdf = 'pdf';
    case Document = 'document';
    case Spreadsheet = 'spreadsheet';
    case Presentation = 'presentation';
    case Archive = 'archive';
    case Text = 'text';
    case Other = 'other';

    /**
     * Derive the normalized file type from a MIME type.
     * Never derive it from the filename extension.
     */
    public static function fromMime(string $mime): self
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));

        if ($mime === 'application/pdf') {
            return self::Pdf;
        }

        return match (true) {
            str_starts_with($mime, 'image/') => self::Image,
            str_starts_with($mime, 'video/') => self::Video,
            str_starts_with($mime, 'audio/') => self::Audio,
            in_array($mime, [
                'application/zip', 'application/x-zip-compressed', 'application/vnd.rar',
                'application/x-rar-compressed', 'application/x-7z-compressed', 'application/x-tar',
                'application/gzip', 'application/x-gzip', 'application/x-bzip2',
            ]) => self::Archive,
            in_array($mime, [
                'text/csv', 'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.oasis.opendocument.spreadsheet',
            ]) => self::Spreadsheet,
            in_array($mime, [
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/vnd.oasis.opendocument.presentation',
            ]) => self::Presentation,
            in_array($mime, [
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/rtf', 'application/vnd.oasis.opendocument.text',
            ]) => self::Document,
            str_starts_with($mime, 'text/') || in_array($mime, [
                'application/json', 'application/xml', 'application/javascript',
                'application/x-php', 'application/x-sh', 'application/yaml',
            ]) => self::Text,
            default => self::Other,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
