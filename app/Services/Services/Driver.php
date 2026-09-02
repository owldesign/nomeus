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

    /** The server binary inside the formula's bin dir (postgres, mysqld, …) — pre-flighted with versionArgs(). */
    public function binary(): string;

    /** Arguments that make binary() print its version and exit (weed: `version`, most: `--version`). @return list<string> */
    public function versionArgs(): array;

    /**
     * One-time setup commands, run in order after the instance dirs exist.
     *
     * @return list<array{label:string, argv:list<string>, cwd:?string, timeout:int}>
     */
    public function initialize(ServiceInstance $instance, string $binDir): array;

    /** Per-instance settings generated once at create (API keys, app secrets) — stored in service.json options. */
    public function defaultOptions(): array;

    /**
     * Listeners besides ->port that the server opens, name => default port. create() allocates
     * each per instance (same standard-if-free rule) into options["<name>_port"].
     *
     * @return array<string, int>
     */
    public function auxPorts(): array;

    /** Directory launchd starts the process in. Instance dir by default; site-bound drivers use the site. */
    public function workingDirectory(ServiceInstance $instance): string;

    /** @return list<string> the foreground process launchd keeps alive */
    public function programArguments(ServiceInstance $instance, string $binDir): array;

    /** @return array<string, string> .env lines for a Laravel app using this instance */
    public function env(ServiceInstance $instance): array;

    /** .env key that names the per-site database/bucket on this service (DB_DATABASE, AWS_BUCKET), or null. */
    public function databaseEnvKey(): ?string;

    /**
     * Idempotent command that creates a database/bucket, or null when the type has none.
     *
     * @return array{label:string, argv:list<string>, cwd:?string, timeout:int, tolerate?:string}|null  tolerate: regex on output meaning "already exists"
     */
    public function createDatabasePlan(ServiceInstance $instance, string $binDir, string $name): ?array;

    /**
     * Lock/identity files the server writes while running and removes on clean shutdown.
     * clone waits for them to vanish from the source, then strips them from the copy;
     * start removes them when launchd isn't holding the instance (so they can't be live).
     *
     * @return list<string> absolute paths
     */
    public function staleFiles(ServiceInstance $instance): array;

    /** Lock files inside an arbitrary data dir (brew's layout, before adoption). @return list<string> */
    public function lockFilesIn(string $dataDir): array;

    /** Where `brew services` keeps this formula's data, e.g. <prefix>/var/postgresql@14. */
    public function brewDataDir(string $prefix, string $formula): ?string;

    /**
     * Commands to run once an adopted instance answers (e.g. PostgreSQL: ensure a `postgres` role).
     *
     * @return list<array{label:string, argv:list<string>, cwd:?string, timeout:int}>
     */
    public function postAdopt(ServiceInstance $instance, string $binDir): array;
}
