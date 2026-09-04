<?php

namespace App\Enums;

/**
 * Every way an upload can fail, and the only text a user is ever shown for it.
 *
 * Two reasons this is an enum and not free-form strings on the row: the UI needs a stable key to
 * render against (FR-29), and mapping exceptions to a fixed vocabulary is what stops an internal
 * message, HTTP body or stack trace from leaking to the browser (NFR-2). The technical detail goes
 * to `uploads.last_error` and the logs, which users never see.
 */
enum FailureCode: string
{
    // Rejected during the upload request. Nothing is stored and no job is queued.
    case UnsupportedType = 'unsupported_type';
    case FileEmpty = 'file_empty';
    case FileTooLarge = 'file_too_large';
    case TooManyFiles = 'too_many_files';
    case CorruptFile = 'corrupt_file';
    case TooManyPages = 'too_many_pages';
    case ImageTooLarge = 'image_too_large';

    // The row exists but never reached a worker.
    case QueueUnavailable = 'queue_unavailable';

    // Failed while processing.
    case LlmUnavailable = 'llm_unavailable';
    case LlmRejectedRequest = 'llm_rejected_request';
    case LlmInvalidOutput = 'llm_invalid_output';
    case NoLabelFound = 'no_label_found';
    case FileMissing = 'file_missing';
    case Unexpected = 'unexpected';

    /**
     * The caps are read from config rather than written into the sentence so that raising a limit
     * cannot leave the user reading a number the validator no longer enforces.
     */
    public function message(): string
    {
        return match ($this) {
            self::UnsupportedType => 'Unsupported file type. Use JPEG, PNG, WebP or PDF.',
            self::FileEmpty => 'This file is empty.',
            self::FileTooLarge => sprintf(
                'File is larger than %d MB.',
                (int) (config('uploads.max_file_bytes') / 1024 / 1024),
            ),
            self::TooManyFiles => sprintf(
                'Upload at most %d files at a time.',
                config('uploads.max_files_per_request'),
            ),
            self::CorruptFile => 'This file appears to be damaged or is not a real image or PDF.',
            self::TooManyPages => sprintf(
                'PDFs are limited to %d pages.',
                config('uploads.max_pdf_pages'),
            ),
            self::ImageTooLarge => sprintf(
                'Image is larger than %d megapixels.',
                (int) (config('uploads.max_image_pixels') / 1_000_000),
            ),
            self::QueueUnavailable => 'We could not queue this file. Please try again in a minute.',
            self::LlmUnavailable => 'The AI service was unavailable for too long. Please upload the file again.',
            self::LlmRejectedRequest => 'The AI service rejected the request.',
            self::LlmInvalidOutput => 'The AI returned data we could not understand.',
            self::NoLabelFound => 'We could not find a product label in this file.',
            self::FileMissing => 'The uploaded file is no longer available.',
            self::Unexpected => 'Something went wrong on our side.',
        };
    }
}
