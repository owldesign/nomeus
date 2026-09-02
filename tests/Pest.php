<?php

use Illuminate\Support\Facades\Process;

pest()->extend(Tests\TestCase::class)
    ->beforeEach(function () {
        // Any Process::fake() in a test makes unmatched commands throw instead of executing for real.
        // (Tests that never call Process::fake() are unaffected: preventStray only applies while faking.)
        Process::preventStrayProcesses();
    })
    ->in('Feature', 'Unit');
