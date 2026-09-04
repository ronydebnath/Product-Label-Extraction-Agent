<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Feature tests boot the app and run against the real Postgres test database inside the
// container (see phpunit.xml). RefreshDatabase wraps each test in a transaction.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
