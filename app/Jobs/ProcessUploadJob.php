<?php

namespace App\Jobs;

use App\Actions\Extraction\ExtractLabelData;
use App\Enums\FailureCode;
use App\Llm\LlmPermanentException;
use App\Llm\LlmTransientException;
use App\Llm\RetryBackoff;
use App\Models\Upload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queue mechanics for one upload. The extraction itself lives in an Action, so what is left here
 * is only the decisions a queue forces: who owns this row, is this failure worth another attempt,
 * and how does the row reach a terminal state no matter how the process ends.
 *
 * The payload is an id and nothing else. The row is the source of truth, so a job that sat in
 * Redis across a deploy cannot act on a stale copy of it.
 */
class ProcessUploadJob implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    public function __construct(public readonly string $uploadId)
    {
        // Read at dispatch so the values travel with the job and match the row's own attempt cap.
        // The timeout sits below the processing lease, so a killed job's row is claimable only
        // after the worker has definitely stopped touching it.
        $this->tries = (int) config('llm.max_attempts');
        $this->timeout = (int) config('llm.job_timeout');
    }

    public function handle(ExtractLabelData $extract, RetryBackoff $backoff): void
    {
        $upload = Upload::query()->find($this->uploadId);

        if ($upload === null) {
            // Deleted between dispatch and delivery. Nothing to do and nothing wrong.
            Log::warning('upload.job_missing_row', ['upload_id' => $this->uploadId]);

            return;
        }

        if ($upload->status->isTerminal()) {
            Log::info('upload.job_skipped_terminal', $this->context($upload));

            return;
        }

        // Whoever wins this UPDATE owns the row. A duplicate or redelivered job loses and stops,
        // which is what makes running this job twice harmless.
        if (! $upload->claimForProcessing()) {
            Log::info('upload.job_skipped_claimed', $this->context($upload));

            return;
        }

        try {
            $extract->handle($upload);
            $upload->markCompleted();

            Log::info('upload.completed', $this->context($upload));
        } catch (LlmTransientException $e) {
            $this->retryOrGiveUp($upload, $e, $backoff);
        } catch (LlmPermanentException $e) {
            // Nothing about waiting changes the answer, so this is over.
            $this->giveUp($upload, $e->failureCode, $e);
        } catch (Throwable $e) {
            // Anything unforeseen is a bug in us, not a fact about the upload. Report it, tell the
            // user something neutral, and do not retry: an unknown fault is not known to be safe
            // to repeat.
            report($e);
            $this->giveUp($upload, FailureCode::Unexpected, $e);
        }
    }

    /**
     * The queue's last word on this job, reached when handle() never got to finish: the worker was
     * killed, the job timed out, or attempts ran out inside the queue rather than inside our code.
     *
     * Without this a row could sit in `processing` forever with nobody coming back for it.
     */
    public function failed(?Throwable $e): void
    {
        $upload = Upload::query()->find($this->uploadId);

        if ($upload === null || $upload->status->isTerminal()) {
            // Already terminal: handle() recorded a specific cause and that one is the true one.
            return;
        }

        // TimeoutExceededException extends MaxAttemptsExceededException, so this one check covers
        // both ways the queue gives up on us: the attempt cap, and the job outrunning its timeout.
        $exhausted = $e instanceof MaxAttemptsExceededException;

        $upload->markFailed(
            $exhausted ? FailureCode::LlmUnavailable : FailureCode::Unexpected,
            $e?->getMessage() ?? 'Job failed without an exception.',
        );

        Log::error('upload.failed_by_hook', $this->context($upload) + [
            'exception' => $e === null ? null : $e::class,
        ]);
    }

    private function retryOrGiveUp(Upload $upload, LlmTransientException $e, RetryBackoff $backoff): void
    {
        if ($upload->attempts >= (int) config('llm.max_attempts')) {
            // The service was not having a blip. A clear failure now beats a row that never settles.
            $this->giveUp($upload, FailureCode::LlmUnavailable, $e);

            return;
        }

        $delay = $backoff->secondsFor($upload->attempts, $e->retryAfterSeconds);

        // Back to `queued` before releasing, so the list shows "queued, retrying" rather than a
        // row that looks stuck in processing for the whole of the backoff.
        $upload->returnToQueue($e->getMessage());
        $this->release($delay);

        Log::warning('upload.retrying', $this->context($upload) + [
            'delay_seconds' => $delay,
            'retry_after' => $e->retryAfterSeconds,
            'exception' => $e->getMessage(),
        ]);
    }

    private function giveUp(Upload $upload, FailureCode $code, Throwable $e): void
    {
        $upload->markFailed($code, $e->getMessage());

        Log::error('upload.failed', $this->context($upload) + [
            'failure_code' => $code->value,
            'exception' => $e->getMessage(),
        ]);

        // Tell the queue too, so the job lands in failed_jobs and Horizon shows it. The row is
        // already terminal, so the failed() hook below will find nothing left to do.
        $this->fail($e);
    }

    /** @return array<string, mixed> */
    private function context(Upload $upload): array
    {
        // upload_id is the correlation id: every line about this file, from the request that
        // created it to the job that finished it, carries the same value (FR-30).
        return [
            'upload_id' => $upload->id,
            'attempts' => $upload->attempts,
            'status' => $upload->status->value,
        ];
    }
}
