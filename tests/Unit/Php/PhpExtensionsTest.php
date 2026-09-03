<?php

use App\Services\Php\PhpExtensions;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeServicesWorld;

beforeEach(function () {
    $this->w = new FakeServicesWorld;
    // restartAndWait resolves PhpManager from the container: point it at the same fake world
    config()->set('nomeus.config_path', $this->w->config->path());
    config()->set('nomeus.valet_config_dir', $this->w->valetFs->configDir);
    $this->mock(\App\Support\Probe::class, function ($m) {
        $m->shouldReceive('tcp')->andReturn(false);
        $m->shouldReceive('unix')->andReturn(true);   // valet.sock answers → "php-fpm back"
    });
    $prepend = new \App\Services\Dumps\PrependInstaller($this->w->config, $this->w->brew, new \App\Services\Dumps\CaptureFlag($this->w->config), $this->w->shell, new \App\Services\Php\XdebugState($this->w->config));
    $this->ext = new PhpExtensions($this->w->brew, $this->w->shell, $prepend);
    $this->mods = ['Core', 'json', 'PDO'];
    Process::fake([
        "*php' '-m'*" => fn () => Process::result("[PHP Modules]\n".implode("\n", $this->mods)."\n\n[Zend Modules]\n"),
        '*brew*trust*' => Process::result(''),
        '*brew*install*' => function () { $this->mods[] = 'redis'; return Process::result("==> Pouring redis@8.4\n"); },
    ]);
    touch("{$this->w->valetFs->configDir}/valet.sock");
});

afterEach(fn () => $this->w->destroy());

it('reads php -m per version and installs from the tap with a restart', function () {
    expect($this->ext->loaded('8.4'))->toBe(['core', 'json', 'pdo'])
        ->and($this->ext->has('8.4', 'redis'))->toBeFalse()
        ->and($this->ext->loaded('7.4'))->toBe([]);                            // no such php

    $lines = [];
    $this->ext->install('8.4', 'redis', function (string $l) use (&$lines) { $lines[] = $l; });

    $bin = $this->w->brewFs->root.'/bin/brew';
    Process::assertRan(fn ($p) => $p->command === [$bin, 'trust', 'shivammathur/extensions']);
    Process::assertRan(fn ($p) => $p->command === [$bin, 'install', 'shivammathur/extensions/redis@8.4']);
    Process::assertRan(fn ($p) => $p->command === [$this->w->valetFs->valetBin(), 'restart', 'php']);
    expect($this->ext->has('8.4', 'redis'))->toBeTrue()
        ->and(end($lines))->toBe('php 8.4: redis loaded');

    $this->ext->install('8.4', 'redis', function (string $l) use (&$lines) { $lines[] = $l; });   // idempotent
    expect(end($lines))->toBe('php 8.4: redis already loaded');

    expect(fn () => $this->ext->install('7.4', 'redis', fn () => null))->toThrow(RuntimeException::class, 'php@7.4 is not installed')
        ->and(fn () => $this->ext->install('8.4', 'bad name', fn () => null))->toThrow(RuntimeException::class, 'not valid');
});
