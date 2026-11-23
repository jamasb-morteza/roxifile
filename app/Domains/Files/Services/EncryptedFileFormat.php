<?php

namespace App\Domains\Files\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class EncryptedFileFormat
{
    /** Validate the public envelope only; authentication is exclusively the client's responsibility. */
    public function inspect(UploadedFile $file, int $connectionId): string
    {
        $stream = fopen($file->getPathname(), 'rb');
        if ($stream === false) {
            throw ValidationException::withMessages(['file' => 'The encrypted upload could not be read.']);
        }
        try {
            $header = fread($stream, 64);
        } finally {
            fclose($stream);
        }
        if ($header === false || strlen($header) !== 64 || substr($header, 0, 8) !== 'ROXIF001') {
            throw ValidationException::withMessages(['file' => 'A version 1 encrypted file is required.']);
        }
        $fields = unpack('Jsize/Jconnection/Nchunk/Nreserved', substr($header, 40));
        if ($fields === false) {
            throw ValidationException::withMessages(['file' => 'The encrypted file header could not be read.']);
        }
        $size = $fields['size'];
        $chunks = max(1, (int) ceil($size / 1048576));
        if ($size < 0 || $size > 9007199254740991 || $chunks > 0xFFFFFFFF || $fields['connection'] !== $connectionId
            || $fields['chunk'] !== 1048576 || $fields['reserved'] !== 0 || $file->getSize() !== 64 + $size + $chunks * 16) {
            throw ValidationException::withMessages(['file' => 'The encrypted file header or ciphertext length is invalid.']);
        }

        return base64_encode($header);
    }
}
