<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

// Smallest possible job: proves the web -> Redis -> worker path end to end.
// `php artisan queue:ping` dispatches it; the log line it writes carries the hostname of the
// container that ran it, which is how you see that jobs never execute in the web process.
final class PingQueue implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    public function handle(): void
    {
        Log::info('queue.pong', [
            'token' => $this->token,
            'handled_by' => gethostname(),
        ]);
    }
}
