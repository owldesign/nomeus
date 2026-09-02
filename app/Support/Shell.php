<?php

namespace App\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Subprocess wrapper. php-fpm runs with a stripped environment (no PATH, HOME, USER),
 * so every command gets an explicit env that can find brew, valet and composer.
 * The installer records composer_bin and brew_prefix in ~/.devkit/config.json;
 * filesystem discovery is only the fallback.
 */
final class Shell
{
    public function __construct(private readonly DevkitConfig $config) {}

    public function brewPrefix(): string
    {
        $configured = $this->config->get('brew_prefix');
        if (is_string($configured) && is_executable("$configured/bin/brew")) {
            return $configured;
        }
        foreach (['/opt/homebrew', '/usr/local'] as $prefix) {
            if (is_executable("$prefix/bin/brew")) {
                return $prefix;
            }
        }

        return '/opt/homebrew';
    }

    public function composerBinDir(): string
    {
        $configured = $this->config->get('composer_bin');
        if (is_string($configured) && is_dir($configured)) {
            return $configured;
        }
        $home = DevkitConfig::homeDir();
        foreach (["$home/.composer/vendor/bin", "$home/.config/composer/vendor/bin"] as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }

        return "$home/.composer/vendor/bin";
    }

    /**
     * Valet must be invoked through Homebrew's bin symlink: `valet trust` writes a NOPASSWD
     * sudoers rule for exactly "<brew>/bin/valet *", and Valet's wrapper hands sudo the
     * path it was invoked by, unresolved. Any other path prompts for a password — which
     * php-fpm cannot answer.
     */
    public function valetBin(): string
    {
        $configured = config('devkit.valet_bin');
        if (is_string($configured) && $configured !== '' && is_executable($configured)) {
            return $configured;
        }
        $brew = $this->brewPrefix().'/bin/valet';
        if (is_executable($brew)) {
            return $brew;
        }
        $composer = $this->composerBinDir().'/valet';

        return is_executable($composer) ? $composer : 'valet';
    }

    /** The CLI php Valet linked (<brew>/bin/php). PHP_BINARY under fpm is php-fpm itself, so never that. */
    public function phpBin(): string
    {
        $brew = $this->brewPrefix().'/bin/php';
        if (is_executable($brew)) {
            return $brew;
        }

        return PHP_SAPI === 'cli' ? PHP_BINARY : 'php';
    }

    public static function currentUser(): string
    {
        if (function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid(posix_geteuid());
            if (! empty($pw['name'])) {
                return $pw['name'];
            }
        }

        return (string) (getenv('USER') ?: 'unknown');
    }

    /** @return array<string, string> */
    public function env(): array
    {
        $brew = $this->brewPrefix();
        $user = self::currentUser();

        return [
            'HOME' => DevkitConfig::homeDir(),
            'USER' => $user,
            'LOGNAME' => $user,
            // brew bin before composer bin: `valet` on PATH must resolve to the sudoers-trusted symlink.
            'PATH' => implode(':', [
                "$brew/bin", "$brew/sbin",
                '/usr/bin', '/bin', '/usr/sbin', '/sbin',
                $this->composerBinDir(),
            ]),
            'LC_ALL' => 'en_US.UTF-8',
            'HOMEBREW_NO_AUTO_UPDATE' => '1',
            'HOMEBREW_NO_ENV_HINTS' => '1',
        ];
    }

    /** @param  array<int, string>|string  $command */
    public function run(array|string $command, ?string $cwd = null, int $timeout = 120, ?callable $output = null): ProcessResult
    {
        $process = Process::env($this->env())->timeout($timeout);
        if ($cwd !== null) {
            $process = $process->path($cwd);
        }

        return $process->run($command, $output);
    }

    /** Absolute path of a binary on devkit's PATH, or null. */
    public function which(string $bin): ?string
    {
        $result = $this->run(['which', $bin], timeout: 10);
        $path = trim($result->output());

        return $result->successful() && $path !== '' ? $path : null;
    }

    /** True when a process with exactly this name is running. */
    public function running(string $processName): bool
    {
        return $this->run(['pgrep', '-x', $processName], timeout: 10)->successful();
    }
}
