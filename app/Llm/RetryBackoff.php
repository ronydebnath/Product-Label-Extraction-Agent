<?php

namespace App\Llm;

/**
 * How long a failed attempt waits before the next one.
 *
 * Exponential so a service that is genuinely down is not hammered, jittered so that a fleet of
 * workers whose jobs all failed during one outage does not come back in lockstep and cause the
 * next one. Injectable so job tests can bind a fixed delay instead of asserting against a
 * random number.
 */
class RetryBackoff
{
    /**
     * A ceiling on any single wait, including one the service asked for. A hostile or mistaken
     * Retry-After of an hour should not strand somebody's upload for an hour; five minutes is
     * long enough to clear a real incident and short enough that the row still settles today.
     */
    public const int MAX_DELAY_SECONDS = 300;

    public function secondsFor(int $attempt, ?int $retryAfterSeconds = null): int
    {
        $base = (float) config('llm.backoff.base_seconds');
        $multiplier = (float) config('llm.backoff.multiplier');
        $jitter = (float) config('llm.backoff.jitter');

        $exponential = $base * $multiplier ** max(0, $attempt - 1);

        // Multiplicative jitter: +/- 25% of the delay, so the spread grows with the wait rather
        // than becoming negligible against it.
        $spread = $exponential * $jitter;
        $delay = $exponential - $spread + (mt_rand() / mt_getrandmax()) * (2 * $spread);

        // Retry-After is the service telling us when it will listen again. Believe it when it
        // asks for longer than we would have waited; never let it shorten our own backoff.
        $seconds = (int) round(max($delay, (float) ($retryAfterSeconds ?? 0)));

        return max(1, min($seconds, self::MAX_DELAY_SECONDS));
    }
}
