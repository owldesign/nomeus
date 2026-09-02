<?php

namespace Zhuk\DevkitClient;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class DevkitClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/devkit-client.php', 'devkit-client');
    }

    public function boot(): void
    {
        // Only in environments where mail lands in Mailpit; never in production.
        if ($this->app->environment('local', 'development', 'testing')) {
            Event::listen(MessageSending::class, TagOutgoingMail::class);
        }
    }
}
