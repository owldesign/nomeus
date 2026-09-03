<?php

namespace Nomeus\Client;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Nomeus\Client\Dumps\DumpHandler;
use Nomeus\Client\Dumps\Recorder;
use Nomeus\Client\Dumps\Sender;

class NomeusClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nomeus-client.php', 'nomeus-client');
    }

    public function boot(): void
    {
        // Only in environments where mail lands in Mailpit and dumps land in nomeus; never in production.
        if (! $this->app->environment('local', 'development', 'testing')) {
            return;
        }
        Event::listen(MessageSending::class, TagOutgoingMail::class);

        // nomeus's prepend file sets this when capture is on; without it, nothing below runs.
        $host = $_SERVER['NOMEUS_DUMP_SERVER'] ?? null;
        if (is_string($host) && $host !== '' && config('nomeus-client.dumps', true)) {
            DumpHandler::register($host);
            (new Recorder(new Sender($host), $this->app))->register();
        }
    }
}
