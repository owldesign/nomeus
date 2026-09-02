<?php

use App\Http\Middleware\LoopbackOnly;
use App\Http\Middleware\RequireDevkitHeader;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global, not just the api group: the SPA shell is local-only too.
        $middleware->append(LoopbackOnly::class);
        // Unsafe API calls run Valet as root; they must be deliberate, same-origin requests.
        $middleware->api(append: [RequireDevkitHeader::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
