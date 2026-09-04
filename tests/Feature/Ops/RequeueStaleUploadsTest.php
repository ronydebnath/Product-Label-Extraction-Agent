<?php

use App\Enums\FailureCode;
use App\Enums\UploadStatus;
use App\Jobs\ProcessUploadJob;
use App\Llm\RetryBackoff;
use App\Models\Upload;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

function sweep(): void
{
    test()->artisan('uploads:sweep')->assertSuccessful();
}

function staleSeconds(): int
{
    return (int) config('llm.sweeper.queued_stale_after') + 60;
}

it('re-dispatches a queued upload whose job never arrived (FR-32)', function () {
    // Redis lost the job, or it was dispatched into a queue nobody was reading. The row is the
    // source of truth, so the row is what we recover from.
    $upload = Upload::factory()->create(['status' => UploadStatus::Queued]);
    $upload->forceFill(['updated_at' => now()->subSeconds(staleSeconds())])->saveQuietly();

    sweep();

    Queue::assertPushed(ProcessUploadJob::class, fn ($job) => $job->uploadId === $upload->id);
});

it('leaves a recently queued upload alone (FR-32)', function () {
    Upload::factory()->create(['status' => UploadStatus::Queued]);

    sweep();

    Queue::assertNothingPushed();
});

it('leaves a queued upload that is waiting out its backoff alone (FR-19, FR-32)', function () {
    // A released job sits in Redis with a delay of up to five minutes. The staleness threshold is
    // deliberately longer than that, so a row waiting its turn is not mistaken for a lost one.
    $upload = Upload::factory()->create(['status' => UploadStatus::Queued, 'attempts' => 2]);
    $upload->forceFill(['updated_at' => now()->subSeconds(RetryBackoff::MAX_DELAY_SECONDS - 30)])->saveQuietly();

    sweep();

    Queue::assertNothingPushed();
});

it('re-dispatches an upload whose worker died with attempts to spare (FR-32)', function () {
    $upload = Upload::factory()->processing(stale: true, attempts: 2)->create();

    sweep();

    Queue::assertPushed(ProcessUploadJob::class, fn ($job) => $job->uploadId === $upload->id);
    // Still processing: the re-dispatched job takes it over through the stale lease, and only
    // the worker that wins the claim increments the attempt.
    expect($upload->refresh()->status)->toBe(UploadStatus::Processing);
});

it('fails an upload whose worker died with no attempts left (FR-32)', function () {
    $upload = Upload::factory()->processing(stale: true, attempts: (int) config('llm.max_attempts'))->create();

    sweep();

    Queue::assertNothingPushed();
    $upload->refresh();
    expect($upload->status)->toBe(UploadStatus::Failed)
        ->and($upload->failure_code)->toBe(FailureCode::LlmUnavailable->value);
});

it('leaves an upload with a live lease alone (FR-12)', function () {
    // Somebody is working on it right now. Touching it would be the sweeper causing the very
    // double-processing it exists to prevent.
    $upload = Upload::factory()->processing(stale: false, attempts: 1)->create();

    sweep();

    Queue::assertNothingPushed();
    expect($upload->refresh()->status)->toBe(UploadStatus::Processing);
});

it('never touches terminal rows (FR-32)', function () {
    $completed = Upload::factory()->completed()->create();
    $failed = Upload::factory()->failed()->create();

    foreach ([$completed, $failed] as $upload) {
        $upload->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
    }

    sweep();

    Queue::assertNothingPushed();
    expect($completed->refresh()->status)->toBe(UploadStatus::Completed)
        ->and($failed->refresh()->status)->toBe(UploadStatus::Failed);
});

it('reports what it did so a cron log is worth reading (NFR-5)', function () {
    $upload = Upload::factory()->create(['status' => UploadStatus::Queued]);
    $upload->forceFill(['updated_at' => now()->subSeconds(staleSeconds())])->saveQuietly();

    $this->artisan('uploads:sweep')
        ->expectsOutputToContain('re-dispatched 1')
        ->assertSuccessful();
});
