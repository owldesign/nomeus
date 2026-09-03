<?php

use App\Services\BrewBridge;
use App\Services\Dumps\CaptureFlag;
use App\Services\Dumps\PrependInstaller;
use App\Services\Php\IniManager;
use App\Services\Php\XdebugManager;
use App\Services\Php\XdebugState;
use App\Support\NomeusConfig;
use App\Support\Probe;
use App\Support\Shell;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeBrew;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nomeus-xdebug-'.uniqid();
    mkdir("{$this->root}/nomeus", 0755, true);
    $this->brewFs = (new FakeBrew)->installed('8.3', '8.3.26')->installed('8.4', '8.4.25')->linked('8.4');
    file_put_contents("{$this->root}/nomeus/config.json", json_encode(['brew_prefix' => $this->brewFs->root, 'xdebug' => ['port' => 9003]]));
    $config = new NomeusConfig("{$this->root}/nomeus/config.json");
    $shell = new Shell($config);
    $brew = new BrewBridge($shell);
    $this->state = new XdebugState($config);
    $this->listening = false;
    $probe = Mockery::mock(Probe::class);
    $probe->shouldReceive('tcp')->andReturnUsing(fn ($h, $p) => $p === 9003 && $this->listening);
    $this->prepend = new PrependInstaller($config, $brew, new CaptureFlag($config), $shell, $this->state);
    $this->watcher = Mockery::mock(\App\Services\Php\XdebugWatcher::class);
    $this->watcher->shouldReceive('enable')->andReturnUsing(function () { $this->watcherOn = true; })->byDefault();
    $this->watcher->shouldReceive('disable')->andReturnUsing(function () { $this->watcherOn = false; })->byDefault();
    $this->watcher->shouldReceive('status')->andReturnUsing(fn () => ['installed' => $this->watcherOn, 'running' => $this->watcherOn, 'pid' => $this->watcherOn ? 42 : null])->byDefault();
    $this->watcherOn = false;
    $this->xdebug = new XdebugManager($config, $brew, $shell, $probe, $this->state, $this->prepend, $this->watcher);
    // restartAndWait resolves PhpManager from the container: point it at this fake world too
    $this->valetFs = new \Tests\Support\FakeValet;
    touch("{$this->valetFs->configDir}/valet.sock");
    config()->set('nomeus.config_path', "{$this->root}/nomeus/config.json");
    config()->set('nomeus.valet_config_dir', $this->valetFs->configDir);
    $this->mock(Probe::class, function ($m) {
        $m->shouldReceive('tcp')->andReturn(false);
        $m->shouldReceive('unix')->andReturn(true);
    });
    $this->ini = fn (string $v) => file_get_contents($this->brewFs->root."/etc/php/{$v}/conf.d/99-nomeus.ini");

    // what the tap leaves behind after `brew install shivammathur/extensions/xdebug@8.4`
    $this->so = $this->brewFs->root.'/opt/xdebug@8.4/xdebug.so';
    $this->tapIni = $this->brewFs->root.'/etc/php/8.4/conf.d/20-xdebug.ini';
    $this->installTap = function () {
        if (! is_dir(dirname($this->so))) {
            mkdir(dirname($this->so), 0755, true);
        }
        file_put_contents($this->so, 'ELF');
        file_put_contents($this->tapIni, "[xdebug]\nzend_extension=\"{$this->so}\"\n");
    };
    Process::fake([
        '*brew*trust*' => Process::result(''),
        '*brew*install*' => function () {
            ($this->installTap)();

            return Process::result("==> Pouring xdebug@8.4\n");
        },
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
    $this->brewFs->destroy();
    $this->valetFs->destroy();
});

it('installs from the tap, reads the .so path, quarantines the tap ini and writes ours with mode off', function () {
    expect($this->xdebug->status()['8.4']['installed'])->toBeFalse();
    $lines = [];

    $r = $this->xdebug->install('8.4', function (string $l) use (&$lines) { $lines[] = $l; });

    $bin = $this->brewFs->root.'/bin/brew';
    Process::assertRan(fn ($p) => $p->command === [$bin, 'trust', 'shivammathur/extensions']);
    Process::assertRan(fn ($p) => $p->command === [$bin, 'install', 'shivammathur/extensions/xdebug@8.4']);
    expect($r)->toBe(['so' => $this->so, 'mode' => 'off'])
        ->and(is_file($this->tapIni))->toBeFalse()
        ->and(is_file($this->tapIni.'.nomeus-off'))->toBeTrue()
        ->and($this->state->get('8.4'))->toBe(['so' => $this->so, 'mode' => 'off'])
        ->and(($this->ini)('8.4'))->toContain('; mode: off')
        ->and(($this->ini)('8.4'))->not->toContain('zend_extension')
        ->and(($this->ini)('8.4'))->toContain('auto_prepend_file=')        // the dumps section survives
        ->and(($this->ini)('8.3'))->toContain('; (not installed)')         // other versions untouched
        ->and(implode("\n", $lines))->toContain('moved the formula\'s 20-xdebug.ini aside');
    $status = $this->xdebug->status();
    expect($status['8.4'])->toMatchArray(['installed' => true, 'so' => $this->so, 'mode' => 'off', 'tap_ini' => false, 'ini_current' => true])
        ->and($status['8.3']['installed'])->toBeFalse();

    expect(fn () => $this->xdebug->install('7.4', fn () => null))->toThrow(RuntimeException::class, 'php@7.4 is not installed');
});

it('writes each mode into the ini and reports whether fpm needs a restart', function () {
    ($this->installTap)();
    $this->xdebug->adopt('8.4', fn () => null);

    expect($this->xdebug->setMode('8.4', 'on'))->toBeTrue();
    $on = ($this->ini)('8.4');
    expect($on)->toContain("zend_extension={$this->so}")
        ->and($on)->toContain('xdebug.mode=debug,develop')
        ->and($on)->toContain('xdebug.start_with_request=yes')
        ->and($on)->toContain('xdebug.client_port=9003')
        ->and(IniManager::modeIn($on))->toBe('on');

    expect($this->xdebug->setMode('8.4', 'on'))->toBeFalse();                          // unchanged → no restart
    expect($this->xdebug->setMode('8.4', 'trigger'))->toBeTrue()
        ->and(($this->ini)('8.4'))->toContain('xdebug.start_with_request=trigger');
    expect($this->xdebug->setMode('8.4', 'off'))->toBeTrue()
        ->and(($this->ini)('8.4'))->not->toContain('zend_extension');

    expect(fn () => $this->xdebug->setMode('8.4', 'loud'))->toThrow(RuntimeException::class, 'off, on or trigger')
        ->and(fn () => $this->xdebug->setMode('8.3', 'on'))->toThrow(RuntimeException::class, 'not installed for php 8.3');
});

it('re-quarantines a tap ini that came back after a brew upgrade, and knows whether the ide listens', function () {
    ($this->installTap)();
    $this->xdebug->adopt('8.4', fn () => null);
    file_put_contents($this->tapIni, "[xdebug]\nzend_extension=\"{$this->so}\"\n");   // brew upgrade restored it

    expect($this->xdebug->status()['8.4']['tap_ini'])->toBeTrue();
    $this->xdebug->setMode('8.4', 'trigger');
    expect(is_file($this->tapIni))->toBeFalse()->and($this->xdebug->status()['8.4']['tap_ini'])->toBeFalse();

    expect($this->xdebug->ideListening())->toBeFalse();
    $this->listening = true;
    expect($this->xdebug->ideListening())->toBeTrue();
});

it('keeps dumps and xdebug sections independent through dumps:install', function () {
    ($this->installTap)();
    $this->xdebug->adopt('8.4', fn () => null);
    $this->xdebug->setMode('8.4', 'on');

    $this->prepend->install();                       // the dumps side regenerating must not lose the xdebug block
    expect(($this->ini)('8.4'))->toContain('xdebug.start_with_request=yes')->and(($this->ini)('8.4'))->toContain('auto_prepend_file=');
});

it('detect follows the ide: watcher installed, ini flips with applyDetect, other modes stop the watcher', function () {
    ($this->installTap)();
    $this->xdebug->adopt('8.4', fn () => null);

    // IDE not listening → detect resolves to off; the watcher is installed
    expect($this->xdebug->setMode('8.4', 'detect'))->toBeFalse();          // off → off: no fpm restart needed
    expect($this->state->get('8.4'))->toBe(['so' => $this->so, 'mode' => 'detect', 'effective' => 'off'])
        ->and(($this->ini)('8.4'))->toContain('; mode: off')
        ->and(($this->ini)('8.4'))->toContain('; detect: switches on when the IDE listens')
        ->and($this->watcherOn)->toBeTrue()
        ->and($this->xdebug->detecting())->toBe(['8.4'])
        ->and($this->xdebug->status()['8.4'])->toMatchArray(['mode' => 'detect', 'effective' => 'off'])
        ->and($this->xdebug->watcher()['running'])->toBeTrue();

    // the IDE starts listening → the heartbeat flips the ini to on (and restarts fpm)
    Process::fake(['*bin/valet*' => Process::result("ok\n")]);
    $lines = [];
    expect($this->xdebug->applyDetect(true, function (string $l) use (&$lines) { $lines[] = $l; }))->toBe(['8.4']);
    expect(($this->ini)('8.4'))->toContain('xdebug.start_with_request=yes')
        ->and(($this->ini)('8.4'))->toContain('; detect: switches off when the IDE stops listening')
        ->and($this->state->get('8.4')['effective'])->toBe('on')
        ->and(implode("\n", $lines))->toContain('xdebug → on (IDE listening)');
    expect($this->xdebug->applyDetect(true, fn () => null))->toBe([]);          // steady state: nothing to do
    expect($this->xdebug->applyDetect(false, fn () => null))->toBe(['8.4']);    // IDE gone → off
    expect(($this->ini)('8.4'))->not->toContain('zend_extension');

    // leaving detect stops the watcher
    expect($this->xdebug->setMode('8.4', 'trigger'))->toBeTrue()->and($this->watcherOn)->toBeFalse()->and($this->xdebug->detecting())->toBe([]);
});
