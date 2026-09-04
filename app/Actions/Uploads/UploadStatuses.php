<?php

namespace App\Actions\Uploads;

use App\Http\Resources\UploadResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * GET /api/uploads?ids=... -- the snapshot the list polls while anything is still moving.
 *
 * Deliberately tiny: it is called every two seconds per open tab, so it does one indexed lookup
 * by primary key and returns no joins.
 */
class UploadStatuses
{
    use AsAction;

    public function asController(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->map(fn (string $id) => trim($id))
            // Anything that is not a uuid cannot be one of ours. Dropping it here keeps junk out
            // of the query rather than relying on Postgres to reject a malformed uuid literal.
            ->filter(fn (string $id) => Str::isUuid($id))
            ->unique()
            ->take((int) config('uploads.max_files_per_request') * 5)
            ->values();

        $uploads = $ids->isEmpty()
            ? collect()
            : $user->uploads()->whereIn('id', $ids)->orderBy('id')->get();

        return response()->json([
            'uploads' => UploadResource::collection($uploads)->resolve(),
        ]);
    }
}
