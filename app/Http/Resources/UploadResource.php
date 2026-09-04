<?php

namespace App\Http\Resources;

use App\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The only shape of an upload that ever leaves the server.
 *
 * Every endpoint goes through here so there is exactly one place to check that `last_error` is
 * absent. That column holds exception text, connection strings and schema violations; it is for
 * operators reading logs, and the browser has no business seeing it (FR-29).
 *
 * @mixin Upload
 */
class UploadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'kind' => $this->kind,
            'size_bytes' => $this->size_bytes,
            'page_count' => $this->page_count,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'max_attempts' => (int) config('llm.max_attempts'),
            'failure_code' => $this->failure_code,
            // The mapped sentence, not the exception. Null while the upload can still succeed.
            'message' => $this->failureMessage(),
            'created_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
        ];
    }
}
