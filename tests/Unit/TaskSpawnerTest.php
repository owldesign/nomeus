<?php

use App\Support\TaskSpawner;

it('returns immediately instead of waiting for the background command', function () {
    $marker = sys_get_temp_dir().'/nomeus-spawn-'.uniqid();

    $start = microtime(true);
    (new TaskSpawner)->spawn('sleep 2 && cd /tmp && touch '.escapeshellarg($marker));
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeLessThan(1.0);

    // …and the command really ran, detached, after we returned.
    $deadline = microtime(true) + 5;
    while (! file_exists($marker) && microtime(true) < $deadline) {
        usleep(100_000);
    }
    expect(file_exists($marker))->toBeTrue();
    @unlink($marker);
});
