<?php

namespace App\Llm;

/**
 * What came back, before anyone has decided whether it is any good. `text` is deliberately the raw
 * string rather than decoded JSON: the model is an untrusted dependency, and parsing is a step that
 * is allowed to fail with its own failure code.
 */
final readonly class LlmResponse
{
    public function __construct(
        public string $text,
        public int $inputTokens,
        public int $outputTokens,
        public int $durationMs,
    ) {}
}
