<?php

namespace App\Services\Services;

use App\Support\ServiceInstance;

/**
 * What devkit needs to know about one kind of service. Drivers only describe: which brew
 * formulae provide it, how to initialize a data dir, the exact argv launchd should run, and
 * the .env lines a Laravel app needs to reach it. ServiceManager does the running.
 */
interface Driver
{
    public function type(): string;

    public function label(): string;

    /** @return list<string> brew formulae, preferred first (e.g. postgresql@17, postgresql@16) */
    public function formulae(): array;

    /** Resolve "17" or "postgresql@17" to a formula from formulae(); null when unknown. */
    public function formulaFor(?string $version): ?string;

    public function defaultPort(): int;

    /**
     * One-time setup commands, run in order after the instance dirs exist.
     *
     * @return list<array{label:string, argv:list<string>, cwd:?string, timeout:int}>
     */
    public function initialize(ServiceInstance $instance, string $binDir): array;

    /** @return list<string> the foreground process launchd keeps alive */
    public function programArguments(ServiceInstance $instance, string $binDir): array;

    /** @return array<string, string> .env lines for a Laravel app using this instance */
    public function env(ServiceInstance $instance): array;

    /**
     * Lock/identity files the server writes while running and removes on clean shutdown.
     * clone waits for them to vanish from the source, then strips them from the copy;
     * start removes them when launchd isn't holding the instance (so they can't be live).
     *
     * @return list<string> absolute paths
     */
    public function staleFiles(ServiceInstance $instance): array;
}
