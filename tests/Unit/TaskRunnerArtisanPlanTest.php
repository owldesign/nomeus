<?php

use App\Services\TaskRunner;

it('shapes a cli task with devkit\'s php, artisan, the args and --no-interaction', function () {
    $plan = app(TaskRunner::class)->artisanPlan('services:start x', ['services:start', 'x'], 120);

    expect($plan['label'])->toBe('services:start x')
        ->and($plan['argv'][1])->toBe(base_path('artisan'))
        ->and(array_slice($plan['argv'], 2))->toBe(['services:start', 'x', '--no-interaction'])
        ->and($plan['cwd'])->toBe(base_path())
        ->and($plan['timeout'])->toBe(120)
        ->and(is_executable($plan['argv'][0]) || $plan['argv'][0] === 'php')->toBeTrue();
});
