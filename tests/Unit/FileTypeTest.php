<?php

namespace Tests\Unit;

use App\Domains\Files\Enums\FileType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FileTypeTest extends TestCase
{
    public static function mimeProvider(): array
    {
        return [
            'pdf' => ['application/pdf', FileType::Pdf],
            'pdf with charset parameter' => ['application/pdf; charset=binary', FileType::Pdf],
            'jpeg' => ['image/jpeg', FileType::Image],
            'png' => ['image/png', FileType::Image],
            'mp4' => ['video/mp4', FileType::Video],
            'mp3' => ['audio/mpeg', FileType::Audio],
            'zip' => ['application/zip', FileType::Archive],
            'rar' => ['application/vnd.rar', FileType::Archive],
            '7z' => ['application/x-7z-compressed', FileType::Archive],
            'gzip' => ['application/gzip', FileType::Archive],
            'plain text' => ['text/plain', FileType::Text],
            'json' => ['application/json', FileType::Text],
            'xml' => ['application/xml', FileType::Text],
            'csv is a spreadsheet' => ['text/csv', FileType::Spreadsheet],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', FileType::Spreadsheet],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', FileType::Document],
            'doc' => ['application/msword', FileType::Document],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', FileType::Presentation],
            'unknown binary' => ['application/octet-stream', FileType::Other],
        ];
    }

    #[DataProvider('mimeProvider')]
    public function test_file_type_is_derived_from_mime_type(string $mime, FileType $expected): void
    {
        $this->assertSame($expected, FileType::fromMime($mime));
    }

    public function test_mime_matching_is_case_insensitive(): void
    {
        $this->assertSame(FileType::Image, FileType::fromMime('IMAGE/PNG'));
    }
}
