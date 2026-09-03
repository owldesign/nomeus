<?php

use App\Support\Shell;

it('does not leak nomeus\'s own .env into child processes', function () {
    $_ENV['APP_KEY'] = 'base64:nomeus';
    $_ENV['DB_CONNECTION'] = 'sqlite';
    $env = app(Shell::class)->env();

    expect($env['APP_KEY'])->toBeFalse()             // false = unset in the child
        ->and($env['DB_CONNECTION'])->toBeFalse()
        ->and($env['PATH'])->toBeString()             // explicit keys are untouched
        ->and($env['LC_ALL'])->toBe('en_US.UTF-8');
    unset($_ENV['APP_KEY'], $_ENV['DB_CONNECTION']);
});
