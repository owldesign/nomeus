<?php

namespace App\Providers;

use App\Services\LaunchdManager;
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

        $this->app->singleton(LaunchdManager::class, fn ($app) => new LaunchdManager(
            $app->make(Shell::class),
            (string) (config('devkit.launch_agents_dir') ?: DevkitConfig::homeDir().'/Library/LaunchAgents'),
            (int) (config('devkit.uid') ?: (function_exists('posix_getuid') ? posix_getuid() : 501)),
        ));
    }

    public function boot(): void {}
}
