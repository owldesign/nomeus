<?php

namespace App\Providers;

use App\Services\ValetBridge;
use App\Support\DevkitConfig;
use App\Support\Shell;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DevkitConfig::class, fn () => new DevkitConfig((string) config('devkit.config_path')));

        $this->app->singleton(ValetBridge::class, fn ($app) => new ValetBridge(
            $app->make(Shell::class),
            (string) config('devkit.valet_config_dir'),
        ));
    }

    public function boot(): void {}
}
