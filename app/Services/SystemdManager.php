<?php

namespace App\Services;

use App\Support\Shell;
use RuntimeException;

/**
 * The Linux twin of LaunchdManager: one systemd --user unit per instance under
 * ~/.config/systemd/user/nomeus-svc-<name>.service. "loaded" here means enabled-or-active;
 * bootstrap = daemon-reload + start, bootout = stop, disable = disable (sticks across logins).
 * Requires `loginctl enable-linger <user>` for units to outlive the login session (the installer does it).
 */
final class SystemdManager implements ProcessManager
{
    public function __construct(
        private readonly Shell $shell,
        private readonly string $unitsDir,
    ) {}

    public function label(string $name): string
    {
        return 'nomeus-svc-'.$name;
    }

    public function plistPath(string $name): string
    {
        return rtrim($this->unitsDir, '/').'/'.$this->label($name).'.service';
    }

    public function domain(): string
    {
        return 'user';
    }

    public function writePlist(string $name, array $argv, string $workingDir, string $logFile, array $env = []): string
    {
        if (! is_dir($this->unitsDir)) {
            mkdir($this->unitsDir, 0755, true);
        }
        file_put_contents($this->plistPath($name), $this->unit($this->label($name), $argv, $workingDir, $logFile, $env), LOCK_EX);
        $this->systemctl(['daemon-reload'], 'daemon-reload');

        return $this->plistPath($name);
    }

    public function readAgent(string $name): ?array
    {
        $path = $this->plistPath($name);
        if (! is_file($path)) {
            return null;
        }
        $argv = [];
        $cwd = null;
        foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
            if (str_starts_with($line, 'ExecStart=')) {
                // unit() writes every argument double-quoted with \\ and \" escaped
                preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', substr($line, 10), $m);
                $argv = array_map(fn (string $a) => str_replace(['\\"', '\\\\'], ['"', '\\'], $a), $m[1]);
            } elseif (str_starts_with($line, 'WorkingDirectory=')) {
                $cwd = substr($line, 17);
            }
        }

        return ['argv' => $argv, 'cwd' => $cwd];
    }

    public function removePlist(string $name): void
    {
        @unlink($this->plistPath($name));
        $this->shell->run(['systemctl', '--user', 'daemon-reload'], timeout: 30);
    }

    /** The unit text; systemd quoting: each argv element quoted, backslashes and quotes escaped. */
    public function unit(string $label, array $argv, string $workingDir, string $logFile, array $env): string
    {
        $q = fn (string $s): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $s).'"';
        $exec = implode(' ', array_map($q, $argv));
        $envLines = '';
        foreach ($env as $k => $v) {
            if (is_string($v) || is_int($v)) {
                $envLines .= 'Environment='.$q("{$k}={$v}")."\n";
            }
        }

        return <<<UNIT
[Unit]
Description=nomeus service {$label}
After=network.target

[Service]
Type=simple
ExecStart={$exec}
WorkingDirectory={$workingDir}
StandardOutput=append:{$logFile}
StandardError=append:{$logFile}
Restart=always
RestartSec=2
{$envLines}
[Install]
WantedBy=default.target

UNIT;
    }

    public function state(string $name): array
    {
        $result = $this->shell->run(['systemctl', '--user', 'show', $this->label($name), '--property=ActiveState,SubState,MainPID,ExecMainStatus,UnitFileState,LoadState'], timeout: 15);
        $props = [];
        foreach (preg_split('/\R/', $result->output()) as $line) {
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $props[$k] = $v;
            }
        }
        $known = $result->successful() && ($props['LoadState'] ?? 'not-found') !== 'not-found';
        $active = ($props['ActiveState'] ?? '') === 'active';
        $pid = (int) ($props['MainPID'] ?? 0);

        return [
            'loaded' => $known && ($active || in_array($props['UnitFileState'] ?? '', ['enabled', 'enabled-runtime'], true)),
            'pid' => $active && $pid > 0 ? $pid : null,
            'state' => $known ? (($props['ActiveState'] ?? null) === 'active' ? 'running' : ($props['SubState'] ?? $props['ActiveState'] ?? null)) : null,
            'last_exit' => $known && isset($props['ExecMainStatus']) && $props['ExecMainStatus'] !== '' ? (int) $props['ExecMainStatus'] : null,
            'disabled' => ($props['UnitFileState'] ?? '') === 'disabled',
        ];
    }

    public function isDisabled(string $name): bool
    {
        return $this->state($name)['disabled'];
    }

    public function bootstrap(string $name): void
    {
        $this->systemctl(['daemon-reload'], 'daemon-reload');
        $this->systemctl(['start', $this->label($name)], 'start');
    }

    public function bootout(string $name): void
    {
        $result = $this->shell->run(['systemctl', '--user', 'stop', $this->label($name)], timeout: 30);
        if (! $result->successful() && ! preg_match('/not loaded|not found|could not be found/i', $result->errorOutput().$result->output())) {
            throw new RuntimeException('systemctl stop failed: '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    public function enable(string $name): void
    {
        $this->systemctl(['enable', $this->label($name)], 'enable');
    }

    public function disable(string $name): void
    {
        $this->systemctl(['disable', $this->label($name)], 'disable');
    }

    public function kickstart(string $name): void
    {
        $this->systemctl(['restart', $this->label($name)], 'restart');
    }

    private function systemctl(array $args, string $what): void
    {
        $result = $this->shell->run(['systemctl', '--user', ...$args], timeout: 30);
        if (! $result->successful()) {
            throw new RuntimeException("systemctl {$what} failed (exit {$result->exitCode()}): ".trim($result->errorOutput() ?: $result->output()));
        }
    }
}
