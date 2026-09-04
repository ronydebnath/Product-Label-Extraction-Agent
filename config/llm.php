<?php

// Everything the extraction agent needs to agree on. The timings are a ladder and must stay in
// this order, or a job can be redelivered while its first run is still talking to the model:
//
//   http timeout 60s  <  job timeout 90s  <  processing lease 120s  <  queue retry_after 150s
//
// Each step leaves room for the one before it to finish and record what happened.
return [

    'model' => env('OPENAI_MODEL', 'gpt-5.4-mini'),
    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    'api_key' => env('OPENAI_API_KEY'),

    // Connect separately from total: a refused connection should fail in seconds, while a model
    // legitimately reading a ten-page PDF is allowed to take its time.
    'connect_timeout' => 5,
    'timeout' => 60,

    'job_timeout' => 90,
    'lease_seconds' => 120,

    // Five attempts over roughly four minutes of backoff. Beyond that the service is not having a
    // blip, and the user is better served by a clear failure than by a row that never settles.
    'max_attempts' => 5,
    'backoff' => [
        'base_seconds' => 10,
        'multiplier' => 3,
        'jitter' => 0.25,
    ],

    'sweeper' => [
        // How long a row may sit in `queued` before we assume its job was lost. This MUST stay
        // above RetryBackoff::MAX_DELAY_SECONDS: a row released for retry is legitimately queued
        // and waiting, and a shorter threshold would mistake patience for a lost job.
        'queued_stale_after' => 600,
    ],
];
