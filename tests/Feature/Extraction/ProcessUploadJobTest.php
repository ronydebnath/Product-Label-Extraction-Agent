<?php

use App\Actions\Extraction\ExtractLabelData;
use App\Enums\FailureCode;
use App\Enums\UploadStatus;
use App\Jobs\ProcessUploadJob;
use App\Llm\LlmPermanentException;
use App\Llm\LlmTransientException;
use App\Llm\RetryBackoff;
use App\Models\Extraction;
use App\Models\Upload;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PdfFixture;

beforeEach(function () {
    Storage::fake('local');
});

function runJobFor(Upload $upload): ProcessUploadJob
{
    $job = new ProcessUploadJob($upload->id);
    $job->withFakeQueueInteractions();
    $job->handle(app(ExtractLabelData::class), app(RetryBackoff::class));

    return $job;
}

/** A queued upload whose bytes are really on the disk. */
function queuedUpload(array $state = []): Upload
{
    $upload = Upload::factory()->create($state);
    Storage::disk('local')->put($upload->storage_path, PdfFixture::pages(2));

    return $upload;
}

it('takes a queued upload to completed (FR-13)', function () {
    $upload = queuedUpload();
    fakeLlm()->returns(validDocument());

    $job = runJobFor($upload);

    $upload->refresh();
    expect($upload->status)->toBe(UploadStatus::Completed)
        ->and($upload->attempts)->toBe(1)
        ->and($upload->completed_at)->not->toBeNull()
        ->and($upload->failure_code)->toBeNull()
        ->and(Extraction::where('upload_id', $upload->id)->count())->toBe(1);

    $job->assertNotReleased()->assertNotFailed();
});

describe('transient failure (FR-19)', function () {
    it('returns the row to the queue and releases with the backoff delay', function () {
        $upload = queuedUpload();
        fakeLlm()->fails(new LlmTransientException('503 from upstream'));

        $this->mock(RetryBackoff::class)->shouldReceive('secondsFor')->once()->andReturn(37);

        $job = runJobFor($upload);

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Queued)
            ->and($upload->attempts)->toBe(1)
            ->and($upload->failure_code)->toBeNull()
            ->and($upload->processing_started_at)->toBeNull()
            ->and($upload->last_error)->toContain('503 from upstream');

        $job->assertReleased(delay: 37);
    });

    it('fails terminally once the attempt cap is reached', function () {
        $max = (int) config('llm.max_attempts');
        $upload = queuedUpload(['attempts' => $max - 1]);
        fakeLlm()->fails(new LlmTransientException('still down'));

        $job = runJobFor($upload);

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->attempts)->toBe($max)
            ->and($upload->failure_code)->toBe(FailureCode::LlmUnavailable->value)
            ->and($upload->failed_at)->not->toBeNull();

        // Terminal means terminal: it is not released for yet another attempt.
        $job->assertFailed()->assertNotReleased();
    });
});

describe('permanent failure (FR-20)', function () {
    it('fails at once without another attempt', function () {
        $upload = queuedUpload();
        fakeLlm()->fails(new LlmPermanentException(FailureCode::LlmRejectedRequest, '401 bad key'));

        $job = runJobFor($upload);

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->attempts)->toBe(1)
            ->and($upload->failure_code)->toBe(FailureCode::LlmRejectedRequest->value);

        $job->assertFailed()->assertNotReleased();
    });

    it('shows the user the mapped message and keeps the detail for operators (FR-29)', function () {
        $upload = queuedUpload();
        fakeLlm()->returnsRaw('sorry, I cannot read that');

        runJobFor($upload);

        $upload->refresh();
        expect($upload->failureMessage())->toBe(FailureCode::LlmInvalidOutput->message())
            ->and($upload->failureMessage())->not->toContain('sorry, I cannot read that')
            ->and($upload->last_error)->not->toBeNull();
    });
});

it('records an unexpected error as unexpected rather than leaking it (FR-29)', function () {
    $upload = queuedUpload();
    fakeLlm()->fails(new RuntimeException('undefined method on null'));

    $job = runJobFor($upload);

    $upload->refresh();
    expect($upload->status)->toBe(UploadStatus::Failed)
        ->and($upload->failure_code)->toBe(FailureCode::Unexpected->value)
        ->and($upload->failureMessage())->toBe('Something went wrong on our side.');

    $job->assertFailed();
});

describe('idempotency and concurrency (FR-12, FR-13)', function () {
    it('does nothing to an upload that is already completed', function () {
        $upload = queuedUpload();
        $upload->markCompleted();

        runJobFor($upload);

        expect(fakeLlm()->calls())->toBe(0)
            ->and(Extraction::count())->toBe(0)
            ->and($upload->refresh()->status)->toBe(UploadStatus::Completed);
    });

    it('does nothing to an upload that has already failed', function () {
        $upload = queuedUpload();
        $upload->markFailed(FailureCode::NoLabelFound, 'earlier run');

        runJobFor($upload);

        expect(fakeLlm()->calls())->toBe(0)
            ->and($upload->refresh()->attempts)->toBe(0);
    });

    it('leaves an upload another worker is actively processing alone', function () {
        // Redelivery, or a duplicate dispatch. The lease is fresh, so somebody else has it.
        $upload = queuedUpload(['status' => UploadStatus::Processing, 'processing_started_at' => now(), 'attempts' => 1]);

        runJobFor($upload);

        expect(fakeLlm()->calls())->toBe(0)
            ->and($upload->refresh()->attempts)->toBe(1);
    });

    it('takes over an upload whose worker died and left a stale lease', function () {
        // A killed worker (OOM, deploy, SIGKILL) leaves the row in processing forever. The lease
        // expiring is what lets the next worker pick it up, and it counts as an attempt.
        $upload = queuedUpload([
            'status' => UploadStatus::Processing,
            'processing_started_at' => now()->subSeconds((int) config('llm.lease_seconds') + 60),
            'attempts' => 1,
        ]);
        fakeLlm()->returns(validDocument());

        runJobFor($upload);

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Completed)
            ->and($upload->attempts)->toBe(2);
    });

    it('never writes a second extraction for the same upload', function () {
        $upload = queuedUpload();
        fakeLlm()->returns(validDocument());

        runJobFor($upload);
        // The row is completed now, so a redelivery is refused before any work happens.
        runJobFor($upload);

        expect(Extraction::where('upload_id', $upload->id)->count())->toBe(1);
    });

    it('does nothing when the upload row has been deleted', function () {
        $upload = queuedUpload();
        $id = $upload->id;
        $upload->delete();

        $job = new ProcessUploadJob($id);
        $job->withFakeQueueInteractions();
        $job->handle(app(ExtractLabelData::class), app(RetryBackoff::class));

        expect(fakeLlm()->calls())->toBe(0);
        $job->assertNotReleased();
    });
});

describe('the failed() hook (NFR-3)', function () {
    it('makes a row terminal when the queue gave up without handle() finishing', function () {
        // MaxAttemptsExceededException is thrown by the worker, not by us, so handle() never runs
        // and nothing else would move this row off processing.
        $upload = queuedUpload(['status' => UploadStatus::Processing, 'processing_started_at' => now(), 'attempts' => 5]);

        (new ProcessUploadJob($upload->id))->failed(new MaxAttemptsExceededException('too many attempts'));

        $upload->refresh();
        expect($upload->status)->toBe(UploadStatus::Failed)
            ->and($upload->failure_code)->toBe(FailureCode::LlmUnavailable->value);
    });

    it('leaves an already terminal row exactly as it was', function () {
        $upload = queuedUpload();
        $upload->markFailed(FailureCode::NoLabelFound, 'first cause wins');

        (new ProcessUploadJob($upload->id))->failed(new MaxAttemptsExceededException('later noise'));

        expect($upload->refresh()->failure_code)->toBe(FailureCode::NoLabelFound->value);
    });

    it('survives being called with no exception at all', function () {
        $upload = queuedUpload(['status' => UploadStatus::Processing, 'processing_started_at' => now(), 'attempts' => 1]);

        (new ProcessUploadJob($upload->id))->failed(null);

        expect($upload->refresh()->status)->toBe(UploadStatus::Failed);
    });
});

it('runs end to end through the real Redis queue (AC-1)', function () {
    config(['queue.default' => 'redis']);
    Redis::connection()->client()->flushdb();

    $upload = queuedUpload();
    fakeLlm()->returns(validDocument());

    ProcessUploadJob::dispatch($upload->id);
    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true]);

    expect($upload->refresh()->status)->toBe(UploadStatus::Completed);
});
