<?php

use App\Support\NomeusConfig;
use App\Support\Platform;

afterEach(fn () => Platform::force(null));

it('detects the platform and can be forced', function () {
    // NOMEUS_PLATFORM wins when set (CI runs the macOS paths on ubuntu that way); otherwise the OS decides
    $env = getenv('NOMEUS_PLATFORM');
    expect(Platform::name())->toBe(in_array($env, ['macos', 'linux'], true) ? $env : (PHP_OS_FAMILY === 'Darwin' ? 'macos' : 'linux'));

    Platform::force('linux');
    expect(Platform::isLinux())->toBeTrue()
        ->and(Platform::defaultBrewPrefixes()[0])->toBe('/home/linuxbrew/.linuxbrew')
        ->and(Platform::unitsDir())->toBe(NomeusConfig::homeDir().'/.config/systemd/user')
        ->and(Platform::openCommand())->toBe('xdg-open')
        ->and(Platform::label())->toBe('Linux');

    Platform::force('macos');
    expect(Platform::isMac())->toBeTrue()
        ->and(Platform::defaultBrewPrefixes())->toBe(['/opt/homebrew', '/usr/local'])
        ->and(Platform::unitsDir())->toEndWith('/Library/LaunchAgents')
        ->and(Platform::openCommand())->toBe('open');
});

it('binds the supervisor by platform', function () {
    Platform::force('macos');
    app()->forgetInstance(\App\Services\ProcessManager::class);
    expect(app(\App\Services\ProcessManager::class))->toBeInstanceOf(\App\Services\LaunchdManager::class);

    Platform::force('linux');
    app()->forgetInstance(\App\Services\ProcessManager::class);
    expect(app(\App\Services\ProcessManager::class))->toBeInstanceOf(\App\Services\SystemdManager::class);
});
