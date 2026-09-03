<?php

namespace App\Services\Php;

use App\Support\Shell;
use RuntimeException;

/**
 * PHP on Ubuntu/Debian from Ondřej Surý's PPA: /usr/bin/phpX.Y, /etc/php/X.Y/{cli,fpm}/conf.d, phpX.Y-fpm units.
 * Anything under /etc or through apt goes through the root helper (install/linux/nomeus-helper) with a
 * NOPASSWD sudoers rule — a fixed set of verbs, no shell.
 */
final class AptPhp implements PhpProvider
{
    public const HELPER = '/usr/local/bin/nomeus-helper';

    public const SAPIS = ['cli', 'fpm'];

    public function __construct(
        private readonly Shell $shell,
        private readonly string $root = '',          // '' = the real filesystem; tests pass a temp dir
        private readonly string $helper = self::HELPER,
    ) {}

    private function etc(string $version = ''): string
    {
        return $this->root.'/etc/php'.($version !== '' ? "/{$version}" : '');
    }

    public function installedPhp(): array
    {
        $out = [];
        foreach (glob($this->etc().'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (preg_match('/(\d+\.\d+)$/', $dir, $m) && is_file($this->root."/usr/bin/php{$m[1]}")) {
                $out[] = $m[1];
            }
        }
        sort($out, SORT_NATURAL);

        return $out;
    }

    /** The alternatives system decides what `php` is; read the link. */
    public function linkedPhp(): ?string
    {
        $target = @readlink($this->root.'/etc/alternatives/php') ?: '';
        if (preg_match('/php(\d+\.\d+)$/', $target, $m)) {
            return $m[1];
        }
        $installed = $this->installedPhp();

        return $installed === [] ? null : end($installed);
    }

    public function phpPatch(string $version): ?string
    {
        $bin = $this->phpBin($version);
        if ($bin === null) {
            return null;
        }
        $out = trim($this->shell->run([$bin, '-r', 'echo PHP_VERSION;'], timeout: 20)->output());

        return preg_match('/^\d+\.\d+\.\d+/', $out) ? $out : null;
    }

    public function availablePhp(): array
    {
        // the PPA's current line-up; apt-cache would be exact but needs the PPA present first
        return array_values(array_diff(['8.1', '8.2', '8.3', '8.4', '8.5'], $this->installedPhp()));
    }

    public function outdatedPhp(bool $fresh = false): array
    {
        $out = [];
        foreach ($this->installedPhp() as $v) {
            $r = $this->shell->run(['apt-cache', 'policy', "php{$v}-cli"], timeout: 30);
            if (preg_match('/Installed: (\S+)\s+Candidate: (\S+)/', $r->output(), $m) && $m[1] !== $m[2] && $m[1] !== '(none)') {
                $out[$v] = $m[2];
            }
        }

        return $out;
    }

    public function assertVersion(string $version): string
    {
        if (! preg_match('/^(?:php@?)?(\d+\.\d+)$/', $version, $m)) {
            throw new RuntimeException("PHP version must look like 8.3, got [{$version}].");
        }

        return $m[1];
    }

    public function installPlan(string $version): array
    {
        $v = $this->assertVersion($version);
        $pkgs = array_map(fn ($e) => "php{$v}-{$e}", ['cli', 'fpm', 'common', 'mbstring', 'xml', 'curl', 'zip', 'intl', 'bcmath', 'gd', 'pgsql', 'mysql', 'sqlite3', 'readline', 'opcache']);

        return ['label' => "apt install php{$v} (+ fpm, common extensions)", 'argv' => $this->sudo('apt-install', ...$pkgs), 'cwd' => null, 'timeout' => 1800];
    }

    public function upgradePlan(string $version): array
    {
        $v = $this->assertVersion($version);

        return ['label' => "apt upgrade php{$v}-*", 'argv' => $this->sudo('apt-upgrade', "php{$v}"), 'cwd' => null, 'timeout' => 1800];
    }

    public function phpBin(string $version): ?string
    {
        $bin = $this->root."/usr/bin/php{$version}";

        return is_file($bin) ? $bin : null;
    }

    public function iniDirs(string $version): array
    {
        return array_values(array_filter(array_map(fn ($s) => $this->etc($version)."/{$s}/conf.d", self::SAPIS), 'is_dir'));
    }

    public function writeIni(string $version, string $name, string $content): void
    {
        $this->helper($this->sudo('write-ini', $version, 'all', $name), $content);
    }

    public function removeIni(string $version, string $name): void
    {
        $this->helper($this->sudo('rm-ini', $version, $name));
    }

    public function restartFpmPlans(): array
    {
        return array_map(fn ($v) => ['label' => "systemctl restart php{$v}-fpm", 'argv' => $this->sudo('restart-fpm', $v), 'cwd' => null, 'timeout' => 60], $this->installedPhp());
    }

    public function extensionInstallPlans(string $version, string $ext): array
    {
        return [['label' => "apt install php{$version}-{$ext}", 'argv' => $this->sudo('apt-install', "php{$version}-{$ext}"), 'cwd' => null, 'timeout' => 900]];
    }

    public function xdebugInstallPlans(string $version): array
    {
        return $this->extensionInstallPlans($version, 'xdebug');
    }

    public function xdebugSoCandidates(string $version): array
    {
        $bin = $this->phpBin($version);
        $out = [];
        if ($bin !== null) {
            $dir = trim($this->shell->run([$bin, '-r', 'echo PHP_EXTENSION_DIR;'], timeout: 20)->output());
            if ($dir !== '') {
                $out[] = $this->root.rtrim($dir, '/').'/xdebug.so';
            }
        }
        foreach (glob($this->root.'/usr/lib/php/*/xdebug.so') ?: [] as $so) {
            $out[] = $so;
        }

        return array_values(array_unique($out));
    }

    /** apt's mods-available/xdebug.ini is enabled through conf.d/20-xdebug.ini symlinks per sapi. */
    public function xdebugVendorIniPresent(string $version): bool
    {
        foreach ($this->iniDirs($version) as $dir) {
            if (is_link("{$dir}/20-xdebug.ini") || is_file("{$dir}/20-xdebug.ini")) {
                return true;
            }
        }

        return false;
    }

    public function quarantineXdebug(string $version): bool
    {
        if (! $this->xdebugVendorIniPresent($version)) {
            return false;
        }
        $this->helper($this->sudo('dismod', $version, 'xdebug'));

        return true;
    }

    public function unquarantineXdebug(string $version): bool
    {
        $this->helper($this->sudo('enmod', $version, 'xdebug'));

        return true;
    }

    public function sourceName(): string
    {
        return 'apt (ppa:ondrej/php)';
    }

    /** @return list<string> */
    private function sudo(string ...$args): array
    {
        return ['sudo', '-n', $this->helper, ...$args];
    }

    private function helper(array $argv, ?string $input = null): void
    {
        $result = $this->shell->run($argv, timeout: 120, input: $input);
        if (! $result->successful()) {
            $err = trim($result->errorOutput() ?: $result->output());
            if (str_contains($err, 'a password is required') || str_contains($err, 'sudo:')) {
                $err .= ' — the sudoers rule for '.self::HELPER.' is missing (install/install-linux.sh installs it)';
            }
            throw new RuntimeException(implode(' ', array_slice($argv, 3))." failed: {$err}");
        }
    }
}
