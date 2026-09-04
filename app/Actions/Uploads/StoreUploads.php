<?php

namespace App\Actions\Uploads;

use App\Enums\FailureCode;
use App\Exceptions\InvalidUploadException;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * POST /uploads. Validation is per file (FR-7): one bad file in a batch of twenty does not cost
 * the user the other nineteen, and each rejection is reported against the filename they recognise.
 */
class StoreUploads
{
    use AsAction;

    public function asController(Request $request): JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1'],
        ]);

        /** @var array<int, UploadedFile> $files */
        $files = $request->file('files', []);

        // A request-level cap, refused whole. Accepting the first twenty of twenty-one would be a
        // silent partial success, and the user would have no idea which one was dropped.
        if (count($files) > config('uploads.max_files_per_request')) {
            return response()->json([
                'code' => FailureCode::TooManyFiles->value,
                'message' => FailureCode::TooManyFiles->message(),
                'accepted' => [],
                'rejected' => [],
            ], 422);
        }

        /** @var User $user */
        $user = $request->user();

        $accepted = [];
        $rejected = [];

        foreach ($files as $file) {
            try {
                $upload = CreateUpload::run($user, ValidateUploadedFile::run($file));

                $accepted[] = $this->describe($upload);
            } catch (InvalidUploadException $e) {
                Log::info('upload.rejected', [
                    'user_id' => $user->id,
                    'original_name' => $file->getClientOriginalName(),
                    'failure_code' => $e->failureCode->value,
                ]);

                $rejected[] = [
                    'original_name' => $file->getClientOriginalName(),
                    'code' => $e->failureCode->value,
                    'message' => $e->failureCode->message(),
                ];
            }
        }

        // 201 when anything was created, 422 when nothing was. The body has the same shape either
        // way so the client renders one list of successes and one list of reasons regardless.
        return response()->json([
            'accepted' => $accepted,
            'rejected' => $rejected,
        ], $accepted === [] ? 422 : 201);
    }

    /** @return array<string, mixed> */
    private function describe(Upload $upload): array
    {
        return [
            'id' => $upload->id,
            'original_name' => $upload->original_name,
            'kind' => $upload->kind,
            'size_bytes' => $upload->size_bytes,
            'page_count' => $upload->page_count,
            'status' => $upload->status->value,
            'failure_code' => $upload->failure_code,
            'message' => $upload->failureMessage(),
        ];
    }
}
