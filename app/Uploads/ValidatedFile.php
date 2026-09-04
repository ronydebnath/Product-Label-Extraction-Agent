<?php

namespace App\Uploads;

use Illuminate\Http\UploadedFile;

/**
 * What ValidateUploadedFile learned about a file, so CreateUpload never has to sniff it again.
 * Everything here comes from the bytes; nothing comes from what the client claimed.
 */
final readonly class ValidatedFile
{
    public function __construct(
        public UploadedFile $file,
        public string $mimeType,
        /** 'image' or 'pdf' -- the branch the extraction step takes. */
        public string $kind,
        public string $extension,
        public int $sizeBytes,
        public ?int $pageCount,
    ) {}
}
