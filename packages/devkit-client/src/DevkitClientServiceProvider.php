<?php

namespace Zhuk\DevkitClient;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Zhuk\DevkitClient\Dumps\DumpHandler;
use Zhuk\DevkitClient\Dumps\Recorder;
use Zhuk\DevkitClient\Dumps\Sender;

class DevkitClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/devkit-client.php', 'devkit-client');
    }

    public function boot(): void
    {
        // Only in environments where mail lands in Mailpit and dumps land in devkit; never in production.
        if (! $this->app->environment('local', 'development', 'testing')) {
            return;
        }
        Event::listen(MessageSending::class, TagOutgoingMail::class);

        // devkit's prepend file sets this when capture is on; without it, nothing below runs.
        $host = $_SERVER['DEVKIT_DUMP_SERVER'] ?? null;
        if (is_string($host) && $host !== '' && config('devkit-client.dumps', true)) {
            DumpHandler::register($host);
            (new Recorder(new Sender($host), $this->app))->register();
        }
    }
}
