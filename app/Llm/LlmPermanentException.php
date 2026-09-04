<?php

namespace App\Llm;

use App\Enums\FailureCode;
use RuntimeException;
use Throwable;

/**
 * A failure that will repeat identically on every attempt: a rejected request, an unparsable
 * answer, a refusal. Retrying spends money to reach the same place, so the job fails at once.
 */
class LlmPermanentException extends RuntimeException
{
    public function __construct(
        public readonly FailureCode $failureCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
