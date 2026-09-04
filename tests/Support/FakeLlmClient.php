<?php

namespace Tests\Support;

use App\Llm\LlmClient;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use Throwable;

/**
 * A scripted stand-in for the model, bound for every feature test so that no test can reach the
 * real API by accident. Outcomes are consumed in order; the last one repeats, so a test that cares
 * about call counts does not also have to script the exact number of calls.
 */
final class FakeLlmClient implements LlmClient
{
    /** @var array<int, string|Throwable> */
    private array $outcomes = [];

    private int $calls = 0;

    /** @var array<int, LlmRequest> */
    private array $requests = [];

    /** @param  array<string, mixed>  $document */
    public function returns(array $document): self
    {
        return $this->push(json_encode($document, JSON_THROW_ON_ERROR));
    }

    /** Whatever the model "said", valid JSON or not. */
    public function returnsRaw(string $text): self
    {
        return $this->push($text);
    }

    public function fails(Throwable $e): self
    {
        return $this->push($e);
    }

    /** @param  array<int, string|Throwable|array<string, mixed>>  $outcomes */
    public function sequence(array $outcomes): self
    {
        foreach ($outcomes as $outcome) {
            is_array($outcome) ? $this->returns($outcome) : $this->push($outcome);
        }

        return $this;
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function lastRequest(): ?LlmRequest
    {
        return $this->requests === [] ? null : $this->requests[array_key_last($this->requests)];
    }

    public function extract(LlmRequest $request): LlmResponse
    {
        $this->requests[] = $request;
        $index = $this->calls;
        $this->calls++;

        if ($this->outcomes === []) {
            throw new \LogicException('FakeLlmClient was called but no outcome was scripted.');
        }

        $outcome = $this->outcomes[min($index, count($this->outcomes) - 1)];

        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return new LlmResponse($outcome, inputTokens: 1000, outputTokens: 200, durationMs: 42);
    }

    private function push(string|Throwable $outcome): self
    {
        $this->outcomes[] = $outcome;

        return $this;
    }
}
