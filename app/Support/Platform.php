<?php

namespace App\Support;

/**
 * The few facts that differ between macOS and Linux, in one place. Everything else asks here.
 * NOMEUS_PLATFORM=linux|macos overrides detection (tests, or running the Linux code paths on a Mac to read them).
 */
final class Platform
{
    public const MACOS = 'macos';

    public const LINUX = 'linux';

    private static ?string $forced = null;

    public static function force(?string $platform): void
    {
        self::$forced = $platform;
    }

    public static function name(): string
    {
        $env = self::$forced ?? getenv('NOMEUS_PLATFORM') ?: null;
        if (in_array($env, [self::MACOS, self::LINUX], true)) {
            return $env;
        }

        return PHP_OS_FAMILY === 'Darwin' ? self::MACOS : self::LINUX;
    }

    public static function isMac(): bool
    {
        return self::name() === self::MACOS;
    }

    public static function isLinux(): bool
    {
        return self::name() === self::LINUX;
    }

    /** Where Homebrew lives when nothing says otherwise. */
    public static function defaultBrewPrefixes(): array
    {
        return self::isMac() ? ['/opt/homebrew', '/usr/local'] : ['/home/linuxbrew/.linuxbrew', NomeusConfig::homeDir().'/.linuxbrew'];
    }

    /** Where nomeus keeps its per-user service units. */
    public static function unitsDir(): string
    {
        return self::isMac() ? NomeusConfig::homeDir().'/Library/LaunchAgents' : NomeusConfig::homeDir().'/.config/systemd/user';
    }

    /** The command that opens a URL or file with the desktop's default handler. */
    public static function openCommand(): string
    {
        return self::isMac() ? 'open' : 'xdg-open';
    }

    /** `valet trust`'s footprint: the sudoers file the doctor checks for. */
    public static function sudoersFile(): string
    {
        return '/etc/sudoers.d/valet';
    }

    /** Human name for messages. */
    public static function label(): string
    {
        return self::isMac() ? 'macOS' : 'Linux';
    }
}
