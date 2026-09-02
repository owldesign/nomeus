<?php

namespace App\Services;

use App\Support\Shell;
use RuntimeException;

/**
 * One launchd user agent per service instance, under ~/Library/LaunchAgents, in the gui/<uid>
 * domain. Stop is bootout + disable so it sticks across logins; start is enable + bootstrap.
 * brew's own homebrew.mxcl.* agents are never touched.
 */
final class LaunchdManager
{
    public const PREFIX = 'dev.zhuk.devkit.svc.';

    public function __construct(
        private readonly Shell $shell,
        private readonly string $agentsDir,
        private readonly int $uid,
    ) {}

    public function label(string $name): string
    {
        return self::PREFIX.$name;
    }

    public function plistPath(string $name): string
    {
        return rtrim($this->agentsDir, '/').'/'.$this->label($name).'.plist';
    }

    public function domain(): string
    {
        return "gui/{$this->uid}";
    }

    /** @param  list<string>  $argv  @param  array<string,string>  $env */
    public function writePlist(string $name, array $argv, string $workingDir, string $logFile, array $env = []): string
    {
        if (! is_dir($this->agentsDir)) {
            mkdir($this->agentsDir, 0755, true);
        }
        file_put_contents($this->plistPath($name), $this->plist($this->label($name), $argv, $workingDir, $logFile, $env), LOCK_EX);

        return $this->plistPath($name);
    }

    public function removePlist(string $name): void
    {
        @unlink($this->plistPath($name));
    }

    public function plist(string $label, array $argv, string $workingDir, string $logFile, array $env): string
    {
        $x = fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $args = implode("\n", array_map(fn ($a) => "        <string>{$x($a)}</string>", $argv));
        $envXml = '';
        if ($env !== []) {
            $pairs = implode("\n", array_map(fn ($k, $v) => "        <key>{$x($k)}</key>\n        <string>{$x($v)}</string>", array_keys($env), $env));
            $envXml = "    <key>EnvironmentVariables</key>\n    <dict>\n{$pairs}\n    </dict>\n";
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>{$x($label)}</string>
    <key>ProgramArguments</key>
    <array>
{$args}
    </array>
    <key>WorkingDirectory</key>
    <string>{$x($workingDir)}</string>
{$envXml}    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>ProcessType</key>
    <string>Interactive</string>
    <key>StandardOutPath</key>
    <string>{$x($logFile)}</string>
    <key>StandardErrorPath</key>
    <string>{$x($logFile)}</string>
</dict>
</plist>

XML;
    }

    /** @return array{loaded:bool, pid:?int, state:?string, last_exit:?int, disabled:bool} */
    public function state(string $name): array
    {
        $result = $this->shell->run(['launchctl', 'print', $this->domain().'/'.$this->label($name)], timeout: 15);
        $out = $result->output();
        $loaded = $result->successful();
        preg_match('/^\s*pid = (\d+)/m', $out, $pid);
        preg_match('/^\s*state = (\S+)/m', $out, $state);
        preg_match('/^\s*last exit code = (-?\d+|\(never exited\))/m', $out, $exit);

        return [
            'loaded' => $loaded,
            'pid' => $loaded && isset($pid[1]) ? (int) $pid[1] : null,
            'state' => $loaded ? ($state[1] ?? null) : null,
            'last_exit' => $loaded && isset($exit[1]) && is_numeric($exit[1]) ? (int) $exit[1] : null,
            'disabled' => $this->isDisabled($name),
        ];
    }

    public function isDisabled(string $name): bool
    {
        $result = $this->shell->run(['launchctl', 'print-disabled', $this->domain()], timeout: 15);

        return $result->successful()
            && preg_match('/"'.preg_quote($this->label($name), '/').'"\s*=>\s*(true|disabled)/', $result->output()) === 1;
    }

    public function bootstrap(string $name): void
    {
        if ($this->state($name)['loaded']) {
            return;
        }
        $this->launchctl(['bootstrap', $this->domain(), $this->plistPath($name)], 'bootstrap');
    }

    public function bootout(string $name): void
    {
        $result = $this->shell->run(['launchctl', 'bootout', $this->domain().'/'.$this->label($name)], timeout: 30);
        // 3 = ESRCH: not loaded — already what we want
        if (! $result->successful() && $result->exitCode() !== 3 && ! str_contains($result->errorOutput(), 'No such process')) {
            throw new RuntimeException('launchctl bootout failed: '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    public function enable(string $name): void
    {
        $this->launchctl(['enable', $this->domain().'/'.$this->label($name)], 'enable');
    }

    public function disable(string $name): void
    {
        $this->launchctl(['disable', $this->domain().'/'.$this->label($name)], 'disable');
    }

    public function kickstart(string $name): void
    {
        $this->launchctl(['kickstart', '-k', $this->domain().'/'.$this->label($name)], 'kickstart');
    }

    private function launchctl(array $args, string $what): void
    {
        $result = $this->shell->run(['launchctl', ...$args], timeout: 30);
        if (! $result->successful()) {
            throw new RuntimeException("launchctl {$what} failed (exit {$result->exitCode()}): ".trim($result->errorOutput() ?: $result->output()));
        }
    }
}
