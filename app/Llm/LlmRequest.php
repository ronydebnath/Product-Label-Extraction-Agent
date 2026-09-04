<?php

namespace App\Llm;

/** One extraction request: the document, the instruction, and the shape the answer must take. */
final readonly class LlmRequest
{
    /** @param  array<string, mixed>  $schema */
    public function __construct(
        public string $model,
        public string $prompt,
        public string $filename,
        public string $mimeType,
        /** Raw file bytes. The client decides how to encode them for the wire. */
        public string $contents,
        public array $schema,
        public string $schemaName,
    ) {}

    public function isPdf(): bool
    {
        return $this->mimeType === 'application/pdf';
    }
}
