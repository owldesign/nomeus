<?php

use App\Services\BrewBridge;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->beforeEach(function () {
        // Any Process::fake() in a test makes unmatched commands throw instead of executing for real.
        // (Tests that never call Process::fake() are unaffected: preventStray only applies while faking.)
        Process::preventStrayProcesses();
    })
    ->afterEach(function () {
        // No test may have resolved a BrewBridge on the real prefix: that is how a migration test once
        // rewrote the machine's php ini files. A test needing brew must give NomeusConfig a fake prefix.
        if (app()->resolved(BrewBridge::class)) {
            $prefix = app(BrewBridge::class)->prefix();
            if (! str_contains($prefix, sys_get_temp_dir()) && ! str_contains($prefix, '/nomeus-') && ! str_contains($prefix, '/devkit-')) {
                throw new RuntimeException("Test resolved BrewBridge on the real prefix {$prefix} — point nomeus.config_path at a config with a fake brew_prefix.");
            }
        }
    })
    ->in('Feature', 'Unit');
