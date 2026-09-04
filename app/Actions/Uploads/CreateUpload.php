<?php

namespace App\Actions\Uploads;

use App\Enums\FailureCode;
use App\Enums\UploadStatus;
use App\Jobs\ProcessUploadJob;
use App\Models\Upload;
use App\Models\User;
use App\Uploads\ValidatedFile;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/** Persists one validated file and hands it to the queue. */
class CreateUpload
{
    use AsAction;

    public function __construct(private readonly Dispatcher $dispatcher) {}

    public function handle(User $user, ValidatedFile $validated): Upload
    {
        $path = $this->store($validated);

        $upload = Upload::create([
            'user_id' => $user->id,
            'original_name' => $validated->file->getClientOriginalName(),
            'mime_type' => $validated->mimeType,
            'kind' => $validated->kind,
            'size_bytes' => $validated->sizeBytes,
            'page_count' => $validated->pageCount,
            'content_hash' => hash_file('sha256', (string) $validated->file->getRealPath()),
            'storage_path' => $path,
            'status' => UploadStatus::Queued,
        ]);

        $this->dispatch($upload);

        return $upload;
    }

    /**
     * The row is committed before the job is dispatched, never the other way round: a worker that
     * picks the job up in the same millisecond must be able to find the row.
     */
    private function dispatch(Upload $upload): void
    {
        try {
            $this->dispatcher->dispatch(new ProcessUploadJob($upload->id));
        } catch (Throwable $e) {
            // Redis was reachable when the request started and is not now. Leaving the row in
            // `queued` would strand it: nothing is coming to pick it up, and the sweeper would
            // re-dispatch into the same dead queue. Failing it here is the honest answer.
            Log::error('upload.queue_failed', [
                'upload_id' => $upload->id,
                'exception' => $e->getMessage(),
            ]);

            $upload->markFailed(FailureCode::QueueUnavailable, $e->getMessage());
        }
    }

    /**
     * Stored under a generated uuid on a private disk, partitioned by month so one directory never
     * accumulates every upload ever made. The client's filename is kept in the database for
     * display and never touches the filesystem: it is the one field an attacker fully controls.
     */
    private function store(ValidatedFile $validated): string
    {
        $directory = sprintf('%s/%s', config('uploads.path_prefix'), now()->format('Y/m'));
        $name = sprintf('%s.%s', Str::uuid7()->toString(), $validated->extension);

        Storage::disk(config('uploads.disk'))->putFileAs($directory, $validated->file, $name);

        return "{$directory}/{$name}";
    }
}
