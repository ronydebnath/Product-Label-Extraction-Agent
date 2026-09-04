<?php

namespace App\Llm;

/**
 * The only seam through which this application talks to a model.
 *
 * It exists so the rest of the code depends on an interface rather than on OpenAI, and so every
 * test can bind a fake and script the failures that matter: timeouts, rate limits, refusals and
 * malformed output. Implementations must not retry internally -- retrying is the queue's job, and
 * a client that quietly retries makes the job's attempt count a lie.
 */
interface LlmClient
{
    /**
     * @throws LlmTransientException worth trying again later
     * @throws LlmPermanentException never worth trying again
     */
    public function extract(LlmRequest $request): LlmResponse;
}
