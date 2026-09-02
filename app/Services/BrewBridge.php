<?php

namespace App\Services;

use App\Support\Shell;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Homebrew as devkit needs it: which php@X.Y kegs exist, which one is linked, what the
 * shivammathur tap offers, what's outdated. Reads are filesystem-only (safe and instant
 * from php-fpm); `brew outdated` is the one subprocess and is cached.
 */
final class BrewBridge
{
    public const TAP = 'shivammathur/php';

    public function __construct(private readonly Shell $shell) {}

    public function prefix(): string
    {
        return $this->shell->brewPrefix();
    }

    public function bin(): string
    {
        return $this->prefix().'/bin/brew';
    }

    /** @return list<string> "8.2", "8.3", … — every php@X.Y keg with an opt/ symlink */
    public function installedPhp(): array
    {
        $out = [];
        foreach (glob($this->prefix().'/opt/php@*') ?: [] as $dir) {
            if (preg_match('/php@(\d+\.\d+)$/', $dir, $m) && is_dir($dir)) {
                $out[] = $m[1];
            }
        }
        sort($out, SORT_NATURAL);

        return $out;
    }

    /**
     * Patch version, e.g. "8.4.25": the keg opt/php@X.Y points at. Resolving the symlink rather
     * than globbing Cellar/php@X.Y matters because homebrew-core aliases its current `php` as
     * php@8.5 — that keg lives under Cellar/php/, not Cellar/php@8.5/.
     */
    public function phpPatch(string $version): ?string
    {
        $link = $this->prefix()."/opt/php@{$version}";
        if (is_link($link)) {
            $keg = basename((string) readlink($link));
            if (preg_match('/^\d+\.\d+\.\d+/', $keg)) {
                return $keg;
            }
        }
        $kegs = array_filter(array_map('basename', glob($this->prefix()."/Cellar/php@{$version}/*", GLOB_ONLYDIR) ?: []),
            fn ($k) => preg_match('/^\d+\.\d+\.\d+/', $k));
        if ($kegs === []) {
            return null;
        }
        usort($kegs, 'version_compare');

        return end($kegs) ?: null;
    }

    /** @return list<string> versions the tap can install — read from its checked-out Formula dir */
    public function availablePhp(): array
    {
        $dir = $this->prefix().'/Library/Taps/shivammathur/homebrew-php/Formula';
        $out = [];
        foreach (glob("$dir/php@*.rb") ?: [] as $file) {
            if (preg_match('/^php@(\d+\.\d+)\.rb$/', basename($file), $m)) {
                $out[] = $m[1];
            }
        }
        if ($out === []) {
            return $this->installedPhp(); // tap not checked out yet: nothing new is offered
        }
        sort($out, SORT_NATURAL);

        return array_values(array_unique($out));
    }

    /** The global version: what <brew>/opt/php points at ("8.4"), or the CLI php's version as fallback. */
    public function linkedPhp(): ?string
    {
        $link = $this->prefix().'/opt/php';
        if (is_link($link) && preg_match('#php@(\d+\.\d+)#', (string) readlink($link), $m)) {
            return $m[1];
        }
        $result = $this->shell->run(['php', '-r', 'echo PHP_VERSION;'], timeout: 10);
        if ($result->successful() && preg_match('/^(\d+\.\d+)/', trim($result->output()), $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array<string, string> version => newer patch, e.g. ["8.4" => "8.4.26"].
     * `brew outdated` is slow and needs taps updated (our env sets HOMEBREW_NO_AUTO_UPDATE), so cached.
     */
    public function outdatedPhp(bool $fresh = false): array
    {
        $key = 'devkit.brew.outdated-php';
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 600, function (): array {
            $result = $this->shell->run([$this->bin(), 'outdated', '--formula', '--json=v2'], timeout: 120);
            if (! $result->successful()) {
                return [];
            }
            $data = json_decode($result->output(), true);
            $out = [];
            foreach ((array) ($data['formulae'] ?? []) as $f) {
                if (preg_match('/php@(\d+\.\d+)$/', (string) ($f['name'] ?? ''), $m) && ! empty($f['current_version'])) {
                    $out[$m[1]] = (string) $f['current_version'];
                }
            }

            return $out;
        });
    }

    // ── any formula (services) ───────────────────────────────────────────────

    /** "typesense/tap/typesense-server" → "typesense-server"; opt/ is keyed by the short name. */
    public function shortName(string $formula): string
    {
        return basename($formula);
    }

    public function isFormulaInstalled(string $formula): bool
    {
        return is_dir($this->prefix().'/opt/'.$this->shortName($formula));
    }

    public function formulaBinDir(string $formula): ?string
    {
        $dir = $this->prefix().'/opt/'.$this->shortName($formula).'/bin';

        return is_dir($dir) ? $dir : null;
    }

    /** Keg version the opt/ symlink points at, e.g. "17.6" or "8.4.6". */
    public function formulaVersion(string $formula): ?string
    {
        $link = $this->prefix().'/opt/'.$this->shortName($formula);
        if (! is_link($link)) {
            return null;
        }
        $keg = basename((string) readlink($link));

        return preg_match('/^\d/', $keg) ? $keg : null;
    }

    public function installFormulaPlan(string $formula): array
    {
        if (! preg_match('#^[A-Za-z0-9._@/-]+$#', $formula)) {
            throw new RuntimeException("Invalid formula name [{$formula}].");
        }

        return [
            'label' => "brew install {$formula}",
            'argv' => [$this->bin(), 'install', $formula],
            'cwd' => null,
            'timeout' => 1800,
        ];
    }

    // ── php ──────────────────────────────────────────────────────────────────

    /** @return array{label:string, argv:list<string>, cwd:null, timeout:int} */
    public function installPlan(string $version): array
    {
        $formula = self::TAP.'/php@'.$this->assertVersion($version);

        return [
            'label' => "brew install {$formula}",
            'argv' => [$this->bin(), 'install', $formula],
            'cwd' => null,
            'timeout' => 1800,
        ];
    }

    /** Short name on purpose: brew upgrades whichever tap the keg came from. */
    public function upgradePlan(string $version): array
    {
        $formula = 'php@'.$this->assertVersion($version);

        return [
            'label' => "brew upgrade {$formula}",
            'argv' => [$this->bin(), 'upgrade', $formula],
            'cwd' => null,
            'timeout' => 1800,
        ];
    }

    public function assertVersion(string $version): string
    {
        if (! preg_match('/^(?:php@)?(\d+\.\d+)$/', $version, $m)) {
            throw new RuntimeException("PHP version must look like 8.3, got [{$version}].");
        }

        return $m[1];
    }
}
