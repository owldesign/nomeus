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
            (string) (config('nomeus.launch_agents_dir') ?: \App\Support\Platform::unitsDir()),
        ));

        $this->app->singleton(LaunchdManager::class, fn ($app) => new LaunchdManager(
            $app->make(Shell::class),
            (string) (config('nomeus.launch_agents_dir') ?: \App\Support\Platform::unitsDir()),
            (int) (config('nomeus.uid') ?: (function_exists('posix_getuid') ? posix_getuid() : 501)),
        ));
        $this->app->singleton(\App\Services\SystemdManager::class, fn ($app) => new \App\Services\SystemdManager(
            $app->make(Shell::class),
            (string) (config('nomeus.launch_agents_dir') ?: \App\Support\Platform::unitsDir()),
        ));
        // where php comes from: brew on macOS, apt + the root helper on Linux
        $this->app->singleton(\App\Services\Php\PhpProvider::class, fn ($app) => \App\Support\Platform::isMac()
            ? $app->make(BrewBridge::class)
            : new \App\Services\Php\AptPhp($app->make(Shell::class)));
        // the supervisor everyone else asks for: launchd on macOS, systemd --user on Linux
        $this->app->singleton(\App\Services\ProcessManager::class, fn ($app) => \App\Support\Platform::isMac()
            ? $app->make(LaunchdManager::class)
            : $app->make(\App\Services\SystemdManager::class));
    }

    public function boot(): void {}
}
