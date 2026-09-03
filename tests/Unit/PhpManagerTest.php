<?php

use App\Services\BrewBridge;
use App\Services\PhpManager;
use App\Services\ValetBridge;
use App\Support\NomeusConfig;
use App\Support\Probe;
use App\Support\Shell;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeBrew;
use Tests\Support\FakeValet;

beforeEach(function () {
    Cache::flush();
    $this->valetFs = new FakeValet;
    $this->brewFs = (new FakeBrew)
        ->installed('8.3', '8.3.26')->installed('8.4', '8.4.25')->linked('8.4')
        ->available(['8.1', '8.3', '8.4', '8.5']);
    file_put_contents($this->valetFs->root.'/nomeus.json', json_encode(['brew_prefix' => $this->brewFs->root]));
    config()->set('nomeus.valet_bin', $this->valetFs->valetBin());

    $this->valetFs->parked('alpha');
    $this->valetFs->parked('beta');
    $this->valetFs->isolated('beta', '8.3');
    $this->valetFs->proxied('grafana', 'http://127.0.0.1:3000');
    touch($this->valetFs->configDir.'/valet.sock');
    touch($this->valetFs->configDir.'/valet83.sock');

    $shell = new Shell(new NomeusConfig($this->valetFs->root.'/nomeus.json'));
    $this->probe = Mockery::mock(Probe::class);
    $this->probe->shouldReceive('unix')->andReturnUsing(fn (string $p) => str_ends_with($p, 'valet.sock')); // only the global fpm answers
    $this->manager = new PhpManager(
        new BrewBridge($shell),
        new ValetBridge($shell, $this->valetFs->configDir),
        $shell,
        $this->probe,
    );
    Process::fake(['*brew*outdated*' => Process::result(json_encode(['formulae' => [
        ['name' => 'php@8.3', 'current_version' => '8.3.27'],
    ]]))]);
});

afterEach(function () {
    $this->valetFs->destroy();
    $this->brewFs->destroy();
});

it('merges kegs, sockets, sites and updates into one view', function () {
    $versions = collect($this->manager->versions())->keyBy('version');

    expect($versions->keys()->all())->toBe(['8.3', '8.4'])
        ->and($versions['8.4']->linked)->toBeTrue()
        ->and($versions['8.4']->fpm)->toBeTrue()
        ->and($versions['8.4']->sites)->toBe(['alpha'])          // global serves the non-isolated site; proxy excluded
        ->and($versions['8.4']->patch)->toBe('8.4.25')
        ->and($versions['8.4']->ini)->toBe($this->brewFs->root.'/etc/php/8.4/php.ini')
        ->and($versions['8.4']->outdated)->toBeNull()
        ->and($versions['8.3']->linked)->toBeFalse()
        ->and($versions['8.3']->fpm)->toBeFalse()               // valet83.sock exists but doesn't answer
        ->and($versions['8.3']->sites)->toBe(['beta'])
        ->and($versions['8.3']->outdated)->toBe('8.3.27')
        ->and($this->manager->installable())->toBe(['8.1', '8.5'])
        ->and($this->manager->runningFpmVersions())->toBe(['8.4']);
});

it('plans use/install/update with guards', function () {
    config()->set('nomeus.platform_check', $this->valetFs->root.'/missing.php'); // fall back to min_php 8.2
    $valet = $this->valetFs->valetBin();
    $brew = $this->brewFs->root.'/bin/brew';

    expect($this->manager->usePlan('8.3')['argv'])->toBe([$valet, 'use', 'php@8.3'])
        ->and($this->manager->installPlan('8.1')['argv'])->toBe([$brew, 'install', 'shivammathur/php/php@8.1'])
        ->and($this->manager->updatePlan('8.3')['argv'])->toBe([$brew, 'upgrade', 'php@8.3']);

    expect(fn () => $this->manager->usePlan('8.1'))->toThrow(RuntimeException::class, 'not installed')
        ->and(fn () => $this->manager->installPlan('8.4'))->toThrow(RuntimeException::class, 'already installed')
        ->and(fn () => $this->manager->installPlan('7.4'))->toThrow(RuntimeException::class, 'not offered')
        ->and(fn () => $this->manager->updatePlan('8.1'))->toThrow(RuntimeException::class, 'not installed');
});

it('reads nomeus\'s own PHP floor from composer\'s platform check and refuses to switch below it', function () {
    $check = $this->valetFs->root.'/platform_check.php';
    file_put_contents($check, "<?php\nif (!(PHP_VERSION_ID >= 80400)) { \$issues[] = 'x'; }\n");
    config()->set('nomeus.platform_check', $check);

    expect($this->manager->minPhp())->toBe('8.4')
        ->and(fn () => $this->manager->usePlan('8.3'))->toThrow(RuntimeException::class, 'need 8.4+')
        ->and($this->manager->usePlan('8.4')['argv'][1])->toBe('use');

    config()->set('nomeus.platform_check', $this->valetFs->root.'/missing.php');
    config()->set('nomeus.min_php', '8.2');
    expect($this->manager->minPhp())->toBe('8.2')
        ->and($this->manager->usePlan('8.3')['argv'][2])->toBe('php@8.3');
});

it('reads composer\'s real platform check when no override is configured', function () {
    config()->set('nomeus.platform_check', null); // the shipped default: key present, value null
    config()->set('nomeus.min_php', '1.0');       // if this leaks through, the assertion below fails

    $real = base_path('vendor/composer/platform_check.php');
    if (! is_file($real) || ! preg_match('/PHP_VERSION_ID\s*>=\s*(\d{5,6})/', file_get_contents($real), $m)) {
        $this->markTestSkipped('no composer platform check in this checkout');
    }
    $expected = intdiv((int) $m[1], 10000).'.'.intdiv(((int) $m[1]) % 10000, 100);

    expect($this->manager->minPhp())->toBe($expected)->not->toBe('1.0');
});
