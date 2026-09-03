<?php

namespace App\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Subprocess wrapper. php-fpm runs with a stripped environment (no PATH, HOME, USER),
 * so every command gets an explicit env that can find brew, valet and composer.
 * The installer records composer_bin and brew_prefix in ~/.nomeus/config.json;
 * filesystem discovery is only the fallback.
 */
final class Shell
{
    public function __construct(private readonly NomeusConfig $config) {}

    public function brewPrefix(): string
    {
        $configured = $this->config->get('brew_prefix');
        if (is_string($configured) && is_executable("$configured/bin/brew")) {
            return $configured;
        }
        foreach (Platform::defaultBrewPrefixes() as $prefix) {
            if (is_executable("$prefix/bin/brew")) {
                return $prefix;
            }
        }

        return Platform::defaultBrewPrefixes()[0];
    }

    public function composerBinDir(): string
    {
        $configured = $this->config->get('composer_bin');
        if (is_string($configured) && is_dir($configured)) {
            return $configured;
        }
        $home = NomeusConfig::homeDir();
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
        $configured = config('nomeus.valet_bin');
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
            'HOME' => NomeusConfig::homeDir(),
            'USER' => $user,
            'LOGNAME' => $user,
            // brew bin before composer bin: `valet` on PATH must resolve to the sudoers-trusted symlink.
            'PATH' => implode(':', [
                "$brew/bin", "$brew/sbin",
                '/usr/bin', '/bin', '/usr/sbin', '/sbin',
                $this->composerBinDir(),
            ]),
            // A valid locale is not optional: PostgreSQL on macOS refuses to start without one
            // ("postmaster became multithreaded during startup").
            'LC_ALL' => 'en_US.UTF-8',
            'LANG' => 'en_US.UTF-8',
            'HOMEBREW_NO_AUTO_UPDATE' => '1',
            'HOMEBREW_NO_ENV_HINTS' => '1',
            'HOMEBREW_NO_INSTALL_CLEANUP' => '1',
            'NONINTERACTIVE' => '1',   // brew inside a task has no tty to ask on
        ] + $this->unsetOwnEnv();
    }

    /**
     * Symfony Process hands $_ENV to every child, and Laravel has loaded nomeus's own .env into it —
     * so a site's `php artisan` would run with nomeus's APP_KEY, DB_CONNECTION, APP_NAME… overriding
     * its own .env (key:generate then fails with "No APP_KEY variable was found"). A value of false
     * removes the variable from the child. Explicit keys in env() win over this list.
     *
     * @return array<string, false>
     */
    public function unsetOwnEnv(): array
    {
        $keys = [];
        $own = base_path('.env');
        if (is_file($own)) {
            foreach (preg_split('/\R/', (string) file_get_contents($own)) as $line) {
                if (preg_match('/^\s*(?:export\s+)?([A-Z_][A-Z0-9_]*)\s*=/', $line, $m)) {
                    $keys[] = $m[1];
                }
            }
        }
        // …and whatever else Laravel-shaped is in the environment (phpunit.xml, a shell profile)
        foreach (array_keys($_ENV + $_SERVER) as $k) {
            if (is_string($k) && preg_match('/^(APP|DB|CACHE|SESSION|QUEUE|MAIL|REDIS|LOG|BROADCAST|FILESYSTEM|VITE|VAR_DUMPER|MEMCACHED|AWS|PUSHER|REVERB|SCOUT|MEILISEARCH|TYPESENSE|XDEBUG)_/', $k)) {
                $keys[] = $k;
            }
        }
        $keys = array_diff(array_unique($keys), ['XDEBUG_MODE', 'XDEBUG_TRIGGER', 'XDEBUG_CONFIG', 'XDEBUG_SESSION']);   // a user's deliberate xdebug env stays

        return array_fill_keys(array_values($keys), false);
    }

    /** @param  array<int, string>|string  $command */
    public function run(array|string $command, ?string $cwd = null, int $timeout = 120, ?callable $output = null, ?string $input = null): ProcessResult
    {
        $process = Process::env($this->env())->timeout($timeout);
        if ($cwd !== null) {
            $process = $process->path($cwd);
        }
        if ($input !== null) {
            $process = $process->input($input);
        }

        return $process->run($command, $output);
    }

    /** Absolute path of a binary on nomeus's PATH, or null. */
    public function which(string $bin): ?string
    {
        $result = $this->run(['which', $bin], timeout: 10);
        $path = trim($result->output());

        return $result->successful() && $path !== '' ? $path : null;
    }

    /** True when a process with exactly this name is running. */
    public function running(string $processName): bool
    {
        return $this->run(['pgrep', '-x', $processName], timeout: 10)->successful();   // pgrep is procps on Linux, BSD on macOS: same flag
    }

    /** Open a URL or path with the desktop's default handler (open / xdg-open). */
    public function open(string $target): void
    {
        $this->run([Platform::openCommand(), $target], timeout: 10);
    }
}
