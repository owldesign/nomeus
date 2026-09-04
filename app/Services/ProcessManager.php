<?php

namespace App\Services;

/**
 * A per-user supervisor for nomeus's service instances: launchd on macOS, systemd --user on Linux.
 * Names are instance names ("pg17"); the manager maps them to its own labels and unit files.
 */
interface ProcessManager
{
    public const PREFIX = 'dev.nomeus.svc.';

    /** The supervisor's identifier for an instance: "dev.nomeus.svc.pg17" (launchd) / "nomeus-svc-pg17" (systemd). */
    public function label(string $name): string;

    /** Path of the unit file (plist / .service) for an instance, whether or not it exists. */
    public function plistPath(string $name): string;

    /** The supervisor's scope for this user: "gui/<uid>" (launchd) / "user" (systemd). */
    public function domain(): string;

    /** Write the unit file. @param list<string> $argv  @param array<string,string|false> $env */
    public function writePlist(string $name, array $argv, string $workingDir, string $logFile, array $env = []): string;

    public function removePlist(string $name): void;

    /**
     * What the unit file on disk would run: its argv and working directory. null when there is no file.
     * The agent auditor compares this with what the driver would write today.
     *
     * @return ?array{argv: list<string>, cwd: ?string}
     */
    public function readAgent(string $name): ?array;

    /** @return array{loaded:bool, pid:?int, state:?string, last_exit:?int, disabled:bool} */
    public function state(string $name): array;

    public function isDisabled(string $name): bool;

    /** Load and start (idempotent when already loaded). */
    public function bootstrap(string $name): void;

    /** Stop and unload (tolerates "not loaded"). */
    public function bootout(string $name): void;

    public function enable(string $name): void;

    public function disable(string $name): void;

    /** Restart a loaded unit in place. */
    public function kickstart(string $name): void;
}
