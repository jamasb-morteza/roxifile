<?php

namespace App\Domains\Telegram\Services;

use App\Domains\Files\Models\File;

class TelegramMetadataProjection
{
    public const BOT_CAPTION_LIMIT = 1024;

    public const ROXI_CAPTION_LIMIT = 1024;

    /**
     * Plain text without a Telegram parse mode or formatting entities. User
     * markup stays literal; no HTML/Markdown parser ever receives this text.
     */
    public function caption(File $file, int $limit): string
    {
        $connection = $file->connection;
        $mode = $connection?->encryption_mode;
        if (! in_array($mode, ['none', 'contents', 'contents_and_metadata'], true)
            || ($mode === 'none' && $file->encryption_header !== null)
            || ($mode !== 'none' && $file->encryption_header === null)) {
            return '';
        }

        $protectedMetadata = $mode === 'contents_and_metadata';
        $path = trim(($file->directory->path ?? '').($protectedMetadata ? '' : '/'.$file->name), '/');
        $includeTags = ! $protectedMetadata && $connection?->syncsCaptionTags();
        $tags = ! $includeTags ? '' : $file->tags()->orderBy('tags.id')->pluck('name')->map(function (string $name): string {
            $tag = trim(preg_replace('/[^\p{L}\p{N}_]+/u', '_', $name) ?? '', '_');

            return '#'.(preg_match('/\p{L}/u', $tag) === 1 ? $tag : 'tag_'.$tag);
        })->implode(' ');
        $link = "\nRoxiFile: ".route('files.index', ['file' => $file->uuid]);
        $marker = "\n[Shortened; full metadata in RoxiFile]";
        $available = $limit - $this->length($link) - $this->length($marker) - 60;
        if ($available < 3) {
            throw new \RuntimeException('The RoxiFile link exceeds the Telegram caption limit.');
        }
        $shortened = false;
        $pathProjection = $this->shorten($path, $available, $shortened);
        $available -= $this->length($pathProjection);
        $tagProjection = $protectedMetadata ? '' : $this->shorten($tags, $available, $shortened);
        $available -= $this->length($tagProjection);
        $descriptionProjection = $protectedMetadata ? '' : $this->shorten($file->description ?? '', $available, $shortened);
        $body = 'Path: '.$pathProjection;
        if ($protectedMetadata) {
            $body .= "\nID: ".$file->name;
        } else {
            if ($includeTags) {
                $body .= "\nTags: ".$tagProjection;
            }
            $body .= "\nDescription: ".$descriptionProjection;
        }

        return $body.($shortened ? $marker : '').$link;
    }

    private function length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    private function shorten(string $text, int $limit, bool &$shortened): string
    {
        if ($this->length($text) <= $limit) {
            return $text;
        }
        $shortened = true;
        if ($limit <= 0) {
            return '';
        }
        $result = '';
        foreach (mb_str_split($text) as $character) {
            if ($this->length($result.$character) >= $limit) {
                break;
            }
            $result .= $character;
        }

        return $result.'…';
    }
}
