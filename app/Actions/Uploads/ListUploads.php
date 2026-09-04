<?php

namespace App\Actions\Uploads;

use App\Http\Resources\UploadResource;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/** GET /uploads -- the list, and the limits the dropzone quotes. */
class ListUploads
{
    use AsAction;

    public function asController(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Uploads/Index', [
            'uploads' => UploadResource::collection(
                $user->uploads()
                    // Newest first, with the id as the tie-breaker: timestamps have second
                    // precision, and ten files uploaded together would otherwise shuffle between
                    // renders. UUIDv7 sorts chronologically, so the tie-break is still correct.
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->get()
            ),
            // Sent rather than hard-coded in the UI so the numbers a user is shown are the ones
            // the validator actually enforces.
            'limits' => [
                'max_files' => (int) config('uploads.max_files_per_request'),
                'max_file_bytes' => (int) config('uploads.max_file_bytes'),
                'max_pdf_pages' => (int) config('uploads.max_pdf_pages'),
                'accepted_extensions' => array_values(config('uploads.accepted_mime_types')),
                'accepted_mime_types' => array_keys(config('uploads.accepted_mime_types')),
            ],
        ]);
    }
}
