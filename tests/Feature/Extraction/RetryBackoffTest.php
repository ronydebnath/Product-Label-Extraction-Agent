<?php

use App\Llm\RetryBackoff;

/** @return array<int, int> many samples for one attempt, to see the whole jitter spread */
function samples(int $attempt, ?int $retryAfter = null): array
{
    $backoff = new RetryBackoff;

    return array_map(fn () => $backoff->secondsFor($attempt, $retryAfter), range(1, 200));
}

it('grows exponentially and stays inside the jitter band (FR-19)', function (int $attempt, float $low, float $high) {
    $delays = samples($attempt);

    expect(min($delays))->toBeGreaterThanOrEqual((int) floor($low))
        ->and(max($delays))->toBeLessThanOrEqual((int) ceil($high));
})->with([
    'first retry' => [1, 7.5, 12.5],
    'second' => [2, 22.5, 37.5],
    'third' => [3, 67.5, 112.5],
    'fourth' => [4, 202.5, 337.5],
]);

it('actually varies, so a fleet of workers does not retry in lockstep (FR-19)', function () {
    // The whole purpose of jitter: without it, every job queued during one outage comes back at
    // the same instant and repeats the thundering herd that caused the outage.
    expect(count(array_unique(samples(3))))->toBeGreaterThan(10);
});

it('waits as long as the service asked when that is longer (FR-19)', function () {
    // A 429 with Retry-After is the service telling us exactly when it will listen again.
    // Guessing shorter just burns another attempt.
    expect(min(samples(1, retryAfter: 120)))->toBe(120);
});

it('ignores a Retry-After shorter than our own backoff (FR-19)', function () {
    $delays = samples(3, retryAfter: 5);

    expect(min($delays))->toBeGreaterThanOrEqual(67);
});

it('refuses to park a job for longer than the cap, whatever the header says (NFR-3)', function () {
    // An absurd or hostile Retry-After should not strand an upload for an hour.
    expect(samples(1, retryAfter: 86_400)[0])->toBe(RetryBackoff::MAX_DELAY_SECONDS);
});

it('never returns a negative or zero delay', function () {
    expect(min(samples(1)))->toBeGreaterThan(0);
});
