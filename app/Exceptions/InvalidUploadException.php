<?php

namespace App\Exceptions;

use App\Enums\FailureCode;
use RuntimeException;

/**
 * Thrown by ValidateUploadedFile for one rejected file. It is control flow, not an error: the
 * request continues with the remaining files, so it is never reported to the exception handler.
 */
class InvalidUploadException extends RuntimeException
{
    public function __construct(public readonly FailureCode $failureCode)
    {
        // The enum message is the user-facing one; keeping it here too makes logs readable
        // without a second lookup.
        parent::__construct($failureCode->message());
    }
}
