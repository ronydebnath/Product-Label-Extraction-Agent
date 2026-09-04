<?php

namespace App\Actions\Uploads;

use App\Enums\FailureCode;
use App\Exceptions\InvalidUploadException;
use App\Uploads\ValidatedFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Decides whether one file is worth storing, using only its bytes.
 *
 * The order of the checks is deliberate and is the cheapest-first ordering: PHP's own upload
 * result, then size, then type, then structure. Nothing reads the file's contents until the size
 * cap has passed, so a hostile 2 GB upload is refused without ever being parsed.
 */
class ValidateUploadedFile
{
    use AsAction;

    public function handle(UploadedFile $file): ValidatedFile
    {
        $this->assertPhpAcceptedIt($file);

        $size = (int) $file->getSize();

        if ($size === 0) {
            throw new InvalidUploadException(FailureCode::FileEmpty);
        }

        if ($size > config('uploads.max_file_bytes')) {
            throw new InvalidUploadException(FailureCode::FileTooLarge);
        }

        // getMimeType() runs finfo over the real bytes. getClientMimeType() and the extension are
        // both attacker-controlled and are never consulted.
        $mimeType = (string) $file->getMimeType();
        $accepted = config('uploads.accepted_mime_types');

        if (! array_key_exists($mimeType, $accepted)) {
            throw new InvalidUploadException(FailureCode::UnsupportedType);
        }

        $path = (string) $file->getRealPath();

        return $mimeType === 'application/pdf'
            ? new ValidatedFile($file, $mimeType, 'pdf', $accepted[$mimeType], $size, $this->pdfPageCount($path))
            : new ValidatedFile($file, $mimeType, 'image', $accepted[$mimeType], $size, $this->assertImageIsReadable($path, $mimeType));
    }

    /**
     * A file can arrive broken before any of our rules run: PHP enforces upload_max_filesize and
     * max_file_uploads itself and hands us a stub. Translating those into the same vocabulary
     * keeps the user's explanation identical whether the cap was hit by PHP or by us.
     */
    private function assertPhpAcceptedIt(UploadedFile $file): void
    {
        if ($file->isValid()) {
            return;
        }

        throw new InvalidUploadException(match ($file->getError()) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => FailureCode::FileTooLarge,
            UPLOAD_ERR_NO_FILE => FailureCode::FileEmpty,
            UPLOAD_ERR_PARTIAL => FailureCode::CorruptFile,
            // NO_TMP_DIR, CANT_WRITE and EXTENSION are all our fault, not the user's.
            default => FailureCode::Unexpected,
        });
    }

    /** @return int the page count, having proved the PDF parses and is within the cap */
    private function pdfPageCount(string $path): int
    {
        // pdfinfo reads the cross-reference table rather than trusting the header, which is the
        // only cheap way to tell a real PDF from four magic bytes. Bounded because it is a
        // subprocess handling hostile input.
        $result = Process::timeout(5)->run(['pdfinfo', $path]);

        if (! $result->successful()) {
            throw new InvalidUploadException(FailureCode::CorruptFile);
        }

        $output = $result->output();

        // An encrypted PDF may report its page count perfectly well and still refuse to render,
        // so it is rejected here rather than failing later at the model's expense.
        if (preg_match('/^Encrypted:\s+yes/mi', $output) === 1) {
            throw new InvalidUploadException(FailureCode::CorruptFile);
        }

        if (preg_match('/^Pages:\s+(\d+)/mi', $output, $matches) !== 1) {
            throw new InvalidUploadException(FailureCode::CorruptFile);
        }

        $pages = (int) $matches[1];

        if ($pages < 1) {
            throw new InvalidUploadException(FailureCode::CorruptFile);
        }

        if ($pages > config('uploads.max_pdf_pages')) {
            throw new InvalidUploadException(FailureCode::TooManyPages);
        }

        return $pages;
    }

    /**
     * @return null images have no page count; the return type keeps the two branches symmetrical
     */
    private function assertImageIsReadable(string $path, string $mimeType): null
    {
        // getimagesize parses the header only. That is the point: the dimension cap has to be
        // enforced before anything allocates memory for the pixels. A body that is corrupt below
        // the header is caught later by the model, which is cheap enough and does not require GD.
        $info = @getimagesize($path);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new InvalidUploadException(FailureCode::CorruptFile);
        }

        // finfo and getimagesize disagreeing means the file is lying to one of them.
        if ($info['mime'] !== $mimeType) {
            throw new InvalidUploadException(FailureCode::CorruptFile);
        }

        if (config('uploads.max_image_pixels') < $info[0] * $info[1]) {
            throw new InvalidUploadException(FailureCode::ImageTooLarge);
        }

        return null;
    }
}
