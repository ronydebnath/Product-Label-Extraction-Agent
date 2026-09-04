<?php

namespace App\Console\Commands;

use App\Jobs\PingQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

// First thing to run when "jobs aren't processing": if the pong never appears in the worker
// logs, the problem is the queue plumbing, not the extraction code.
final class QueuePing extends Command
{
    protected $signature = 'queue:ping';

    protected $description = 'Dispatch a trivial job and print the token to look for in the worker log';

    public function handle(): int
    {
        $token = Str::lower(Str::random(8));

        PingQueue::dispatch($token);

        $this->info(sprintf('Dispatched PingQueue token=%s from host %s.', $token, gethostname()));
        $this->line('Look for "queue.pong" with that token in: docker compose logs worker');

        return self::SUCCESS;
    }
}
