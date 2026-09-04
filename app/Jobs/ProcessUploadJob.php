<?php

namespace App\Jobs;

use App\Models\Upload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Stage 3 fills in the body. It exists now because the upload path has to dispatch something, and
 * a job class that does nothing is honest about that while the dispatch behaviour gets its tests.
 *
 * The payload is the upload id and nothing else: the row is the source of truth, so a job that
 * waits in Redis through a deploy cannot act on a stale copy of it (NFR-4).
 */
class ProcessUploadJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $uploadId) {}

    public function handle(): void
    {
        // Stage 3: acquire the lease, run ExtractLabelData, mark the row terminal.
        Upload::query()->find($this->uploadId);
    }
}
