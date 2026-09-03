<?php

use App\Services\Php\AptPhp;
use App\Support\NomeusConfig;
use App\Support\Shell;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/** The Linux provider against a fake /etc/php + /usr/bin tree; the root helper's argv is what matters. */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nomeus-apt-'.uniqid();
    foreach (['8.3', '8.4'] as $v) {
        foreach (['cli', 'fpm'] as $sapi) {
            mkdir("{$this->root}/etc/php/{$v}/{$sapi}/conf.d", 0755, true);
        }
        File::ensureDirectoryExists("{$this->root}/usr/bin");
        file_put_contents("{$this->root}/usr/bin/php{$v}", "#!/bin/sh\n");
    }
    mkdir("{$this->root}/etc/php/7.4/cli/conf.d", 0755, true);   // config dir without a binary: not installed
    File::ensureDirectoryExists("{$this->root}/etc/alternatives");
    symlink("{$this->root}/usr/bin/php8.4", "{$this->root}/etc/alternatives/php");
    File::ensureDirectoryExists("{$this->root}/usr/lib/php/20240924");
    file_put_contents("{$this->root}/usr/lib/php/20240924/xdebug.so", 'ELF');

    $this->helper = "{$this->root}/nomeus-helper";
    $this->php = new AptPhp(new Shell(new NomeusConfig("{$this->root}/config.json")), $this->root, $this->helper);
    $this->written = [];
    Process::fake([
        "*'-r' 'echo PHP_VERSION;'*" => Process::result('8.4.13'),
        "*'-r' 'echo PHP_EXTENSION_DIR;'*" => Process::result('/usr/lib/php/20240924'),
        '*apt-cache*policy*php8.4*' => Process::result("php8.4-cli:\n  Installed: 8.4.13-1\n  Candidate: 8.4.14-1\n"),
        '*apt-cache*policy*' => Process::result("php8.3-cli:\n  Installed: 8.3.20-1\n  Candidate: 8.3.20-1\n"),
        '*nomeus-helper*' => function ($p) {
            $this->written[] = ['argv' => array_slice($p->command, 3), 'input' => $p->input];

            return Process::result('');
        },
    ]);
});

afterEach(fn () => File::deleteDirectory($this->root));

it('reads versions, the alternatives link, patches, ini dirs and outdated from the apt layout', function () {
    expect($this->php->installedPhp())->toBe(['8.3', '8.4'])
        ->and($this->php->linkedPhp())->toBe('8.4')
        ->and($this->php->phpBin('8.4'))->toBe("{$this->root}/usr/bin/php8.4")
        ->and($this->php->phpBin('7.4'))->toBeNull()
        ->and($this->php->phpPatch('8.4'))->toBe('8.4.13')
        ->and($this->php->iniDirs('8.4'))->toBe(["{$this->root}/etc/php/8.4/cli/conf.d", "{$this->root}/etc/php/8.4/fpm/conf.d"])
        ->and($this->php->availablePhp())->toBe(['8.1', '8.2', '8.5'])
        ->and($this->php->outdatedPhp())->toBe(['8.4' => '8.4.14-1'])
        ->and($this->php->assertVersion('php8.4'))->toBe('8.4')
        ->and($this->php->sourceName())->toContain('apt');
});

it('routes every root operation through the helper with validated verbs', function () {
    $this->php->writeIni('8.4', '99-nomeus.ini', "auto_prepend_file=/x\n");
    $this->php->removeIni('8.4', '99-nomeus.ini');
    expect($this->written[0])->toBe(['argv' => ['write-ini', '8.4', 'all', '99-nomeus.ini'], 'input' => "auto_prepend_file=/x\n"])
        ->and($this->written[1]['argv'])->toBe(['rm-ini', '8.4', '99-nomeus.ini'])
        ->and(array_slice($this->written[0]['argv'], 0, 0))->toBe([]);
    Process::assertRan(fn ($p) => array_slice($p->command, 0, 3) === ['sudo', '-n', $this->helper]);

    expect($this->php->restartFpmPlans())->toHaveCount(2)
        ->and($this->php->restartFpmPlans()[1]['argv'])->toBe(['sudo', '-n', $this->helper, 'restart-fpm', '8.4'])
        ->and($this->php->extensionInstallPlans('8.4', 'redis')[0]['argv'])->toBe(['sudo', '-n', $this->helper, 'apt-install', 'php8.4-redis'])
        ->and($this->php->installPlan('8.5')['argv'])->toContain('php8.5-fpm')
        ->and($this->php->upgradePlan('8.4')['argv'])->toBe(['sudo', '-n', $this->helper, 'apt-upgrade', 'php8.4']);

    // xdebug: the .so from the extension dir, apt's conf.d symlink as the vendor ini, phpdismod as quarantine
    expect($this->php->xdebugSoCandidates('8.4')[0])->toBe("{$this->root}/usr/lib/php/20240924/xdebug.so")
        ->and($this->php->xdebugVendorIniPresent('8.4'))->toBeFalse()
        ->and($this->php->quarantineXdebug('8.4'))->toBeFalse();
    symlink('/etc/php/8.4/mods-available/xdebug.ini', "{$this->root}/etc/php/8.4/fpm/conf.d/20-xdebug.ini");
    expect($this->php->xdebugVendorIniPresent('8.4'))->toBeTrue()->and($this->php->quarantineXdebug('8.4'))->toBeTrue();
    expect(end($this->written)['argv'])->toBe(['dismod', '8.4', 'xdebug']);

    // a helper refusal surfaces with the sudoers hint
    Process::fake(['*nomeus-helper*' => Process::result('', 'sudo: a password is required', 1)]);
    expect(fn () => $this->php->writeIni('8.4', 'x.ini', ''))->toThrow(RuntimeException::class, 'sudoers rule');
});
