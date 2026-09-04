<?php

namespace App\Console\Commands;

use App\Enums\FailureCode;
use App\Enums\UploadStatus;
use App\Jobs\ProcessUploadJob;
use App\Llm\RetryBackoff;
use App\Models\Upload;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * The backstop for work the queue lost.
 *
 * Redis can drop a job, a worker can be killed between claiming a row and finishing it, and a
 * dispatch can land in a queue nobody is reading. In all three cases the row is still correct and
 * the queue is not, so the row is what we recover from. Everything here is safe to run twice: a
 * re-dispatched job that races a live one loses the claim and exits.
 */
class RequeueStaleUploads extends Command
{
    protected $signature = 'uploads:sweep';

    protected $description = 'Recover uploads whose queued job was lost or whose worker died';

    public function handle(): int
    {
        $redispatched = $this->redispatchLostJobs() + $this->recoverAbandonedWork();
        $failed = $this->failExhausted();

        $this->info("uploads:sweep re-dispatched {$redispatched}, failed {$failed}");

        return self::SUCCESS;
    }

    /**
     * Rows sitting in `queued` for longer than any legitimate backoff. `updated_at` rather than
     * `created_at` because a retry resets the clock: the question is how long it has been since
     * anything happened to this row, not how old the upload is.
     */
    private function redispatchLostJobs(): int
    {
        $cutoff = now()->subSeconds((int) config('llm.sweeper.queued_stale_after'));

        return $this->dispatchAll(
            Upload::query()
                ->where('status', UploadStatus::Queued->value)
                ->where('updated_at', '<', $cutoff),
            'upload.sweep_requeued',
        );
    }

    /**
     * Rows whose lease has expired with attempts still available. The re-dispatched job takes the
     * row over through the stale-lease branch of claimForProcessing; the sweeper deliberately does
     * not change the status itself, so a worker that is merely slow rather than dead still wins.
     */
    private function recoverAbandonedWork(): int
    {
        return $this->dispatchAll(
            $this->expiredLeases()->where('attempts', '<', (int) config('llm.max_attempts')),
            'upload.sweep_took_over',
        );
    }

    /**
     * Rows whose lease expired with no attempts left. Nothing is coming back for these, so they
     * are failed here rather than left in `processing` where the UI would show them working
     * forever.
     */
    private function failExhausted(): int
    {
        $failed = 0;

        foreach ($this->expiredLeases()->where('attempts', '>=', (int) config('llm.max_attempts'))->cursor() as $upload) {
            if ($upload->markFailed(FailureCode::LlmUnavailable, 'Lease expired with no attempts remaining.')) {
                Log::error('upload.sweep_failed', ['upload_id' => $upload->id, 'attempts' => $upload->attempts]);
                $failed++;
            }
        }

        return $failed;
    }

    /** @return Builder<Upload> */
    private function expiredLeases(): Builder
    {
        // One lease length past the point a live worker would have renewed its claim. The job
        // timeout sits below the lease, so a worker that is still running cannot be swept.
        return Upload::query()
            ->where('status', UploadStatus::Processing->value)
            ->where('processing_started_at', '<', now()->subSeconds((int) config('llm.lease_seconds')));
    }

    /** @param  Builder<Upload>  $query */
    private function dispatchAll(Builder $query, string $event): int
    {
        $count = 0;

        foreach ($query->cursor() as $upload) {
            ProcessUploadJob::dispatch($upload->id);

            Log::warning($event, [
                'upload_id' => $upload->id,
                'attempts' => $upload->attempts,
                'max_delay_seconds' => RetryBackoff::MAX_DELAY_SECONDS,
            ]);

            $count++;
        }

        return $count;
    }
}
