<?php

// Everything the upload path needs to agree on lives here, so the caps quoted in the UI,
// enforced in validation, and mirrored in docker/php/php.ini have a single source.
return [

    // Any disk from config/filesystems.php. `local` writes to the `uploads` named volume,
    // which only works while web and worker share a host; production points this at `s3`
    // (any S3-compatible bucket) because container disks are ephemeral and not shared.
    'disk' => env('UPLOADS_DISK', 'local'),

    // Prefix inside the disk. Files are stored as {prefix}/{Y}/{m}/{uuid}.{ext}; the
    // client-supplied name is never used for a path.
    'path_prefix' => 'uploads',

    'max_files_per_request' => 20,
    'max_file_bytes' => 10 * 1024 * 1024,
    'max_pdf_pages' => 10,
    'max_image_pixels' => 25_000_000,

    // Sniffed MIME types we accept and the extension we store them under. Anything else,
    // including SVG (scriptable) and HEIC (unsupported by the LLM), is rejected.
    'accepted_mime_types' => [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ],

];
