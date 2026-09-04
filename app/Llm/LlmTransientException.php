<?php

namespace App\Llm;

use RuntimeException;
use Throwable;

/** A failure that says nothing about the request: the same call may well work in a minute. */
class LlmTransientException extends RuntimeException
{
    public function __construct(
        string $message,
        /** Seconds the service asked us to wait, when it said so (Retry-After). */
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
