<?php

use App\Console\Commands\RequeueStaleUploads;
use Illuminate\Support\Facades\Schedule;

// Fired by the `scheduler` container (docker/entrypoint.sh scheduler). onOneServer takes a Redis
// lock so that scaling the scheduler by accident cannot run two sweeps at once; withoutOverlapping
// stops a slow sweep from being started again on top of itself.
Schedule::command(RequeueStaleUploads::class)
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();
