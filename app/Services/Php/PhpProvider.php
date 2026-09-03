<?php

namespace App\Services\Php;

/**
 * Where PHP comes from and how its ini/fpm/extensions are managed:
 * Homebrew on macOS (BrewBridge), apt + a root helper on Linux (AptPhp).
 * Everything above this line (PrependInstaller, XdebugManager, PhpExtensions, PhpManager) talks to this only.
 *
 * Plan arrays are the same shape everywhere: ['label' => …, 'argv' => […], 'cwd' => ?string, 'timeout' => int, 'input' => ?string].
 */
interface PhpProvider
{
    /** @return list<string> "8.2", "8.3", … installed, sorted */
    public function installedPhp(): array;

    public function linkedPhp(): ?string;

    /** "8.4.25" for an installed version, null when unknown */
    public function phpPatch(string $version): ?string;

    /** @return list<string> versions that can be installed but aren't */
    public function availablePhp(): array;

    /** @return array<string, string> version => newer patch available */
    public function outdatedPhp(bool $fresh = false): array;

    /** Normalises "php@8.4"/"8.4" and throws on nonsense. */
    public function assertVersion(string $version): string;

    public function installPlan(string $version): array;

    public function upgradePlan(string $version): array;

    /** Absolute path of that version's php binary, or null. */
    public function phpBin(string $version): ?string;

    /** @return list<string> conf.d directories our ini must live in (macOS: one; Linux: cli and fpm) */
    public function iniDirs(string $version): array;

    /** Write $content as $name into every ini dir of the version (root helper on Linux). */
    public function writeIni(string $version, string $name, string $content): void;

    public function removeIni(string $version, string $name): void;

    /** @return list<array> plans that restart php-fpm for all versions (valet restart php / systemctl per version) */
    public function restartFpmPlans(): array;

    /** @return list<array> plans that install an extension (tap on macOS, apt package on Linux) */
    public function extensionInstallPlans(string $version, string $ext): array;

    /** @return list<array> plans that install xdebug for the version */
    public function xdebugInstallPlans(string $version): array;

    /** @return list<string> where xdebug.so might be for that version, most likely first */
    public function xdebugSoCandidates(string $version): array;

    /** The vendor's own always-on xdebug ini (tap's 20-xdebug.ini / apt's conf.d symlink) — present means "needs quarantine". */
    public function xdebugVendorIniPresent(string $version): bool;

    /** Neutralise the vendor's xdebug ini so ours is the only config. Returns true when something was moved. */
    public function quarantineXdebug(string $version): bool;

    /** Restore the vendor's ini (undo quarantine); used only by tests/migration. */
    public function unquarantineXdebug(string $version): bool;

    /** One-line description of the source, for the doctor ("brew", "apt (ondrej)"). */
    public function sourceName(): string;
}
