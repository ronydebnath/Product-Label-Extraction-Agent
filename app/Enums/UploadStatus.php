<?php

namespace App\Enums;

enum UploadStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    // Terminal states never transition again; a re-delivered job must treat them as "nothing to do".
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed => true,
            self::Queued, self::Processing => false,
        };
    }
}
