<?php

namespace App\Providers;

use App\Services\BrewBridge;
use App\Services\BrewServices;
use App\Services\LaunchdManager;
use App\Services\Services\DriverRegistry;
use App\Support\Probe;
use App\Services\ValetBridge;
use App\Support\NomeusConfig;
use App\Support\Shell;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NomeusConfig::class, fn () => new NomeusConfig((string) config('nomeus.config_path')));

        $this->app->singleton(ValetBridge::class, fn ($app) => new ValetBridge(
            $app->make(Shell::class),
            (string) config('nomeus.valet_config_dir'),
        ));

        $this->app->singleton(BrewServices::class, fn ($app) => new BrewServices(
            $app->make(Shell::class),
            $app->make(BrewBridge::class),
            $app->make(DriverRegistry::class),
            $app->make(Probe::class),
            (string) (config('nomeus.launch_agents_dir') ?: NomeusConfig::homeDir().'/Library/LaunchAgents'),
        ));

        $this->app->singleton(LaunchdManager::class, fn ($app) => new LaunchdManager(
            $app->make(Shell::class),
            (string) (config('nomeus.launch_agents_dir') ?: NomeusConfig::homeDir().'/Library/LaunchAgents'),
            (int) (config('nomeus.uid') ?: (function_exists('posix_getuid') ? posix_getuid() : 501)),
        ));
    }

    public function boot(): void {}
}
