<?php

use App\Services\BrewBridge;
use App\Support\DevkitConfig;
use App\Support\Shell;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeBrew;

beforeEach(function () {
    $this->brewFs = (new FakeBrew)
        ->installed('8.2', '8.2.29')->installed('8.3', '8.3.26')->installed('8.4', '8.4.25')
        ->installed('8.5', '8.5.1', formula: 'php')   // homebrew-core alias: opt/php@8.5 → Cellar/php/8.5.1
        ->linked('8.4')
        ->available(['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5']);
    $this->cfg = sys_get_temp_dir().'/devkit-brewcfg-'.uniqid().'.json';
    file_put_contents($this->cfg, json_encode(['brew_prefix' => $this->brewFs->root]));
    $this->brew = new BrewBridge(new Shell(new DevkitConfig($this->cfg)));
});

afterEach(function () {
    $this->brewFs->destroy();
    @unlink($this->cfg);
});

it('reads installed, patch, linked and available from the filesystem alone', function () {
    Process::fake();

    expect($this->brew->prefix())->toBe($this->brewFs->root)
        ->and($this->brew->installedPhp())->toBe(['8.2', '8.3', '8.4', '8.5'])
        ->and($this->brew->phpPatch('8.4'))->toBe('8.4.25')
        ->and($this->brew->phpPatch('8.5'))->toBe('8.5.1')
        ->and($this->brew->phpPatch('7.4'))->toBeNull()
        ->and($this->brew->linkedPhp())->toBe('8.4')
        ->and($this->brew->availablePhp())->toBe(['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5']);
    Process::assertNothingRan();
});

it('parses brew outdated json and caches it', function () {
    Cache::flush();
    Process::fake(['*brew*outdated*' => Process::result(json_encode([
        'formulae' => [
            ['name' => 'php@8.4', 'installed_versions' => ['8.4.25'], 'current_version' => '8.4.26'],
            ['name' => 'shivammathur/php/php@8.3', 'installed_versions' => ['8.3.26'], 'current_version' => '8.3.27'],
            ['name' => 'node', 'installed_versions' => ['22.1.0'], 'current_version' => '22.2.0'],
        ],
        'casks' => [],
    ]))]);

    expect($this->brew->outdatedPhp())->toBe(['8.4' => '8.4.26', '8.3' => '8.3.27'])
        ->and($this->brew->outdatedPhp())->toBe(['8.4' => '8.4.26', '8.3' => '8.3.27']);
    Process::assertRanTimes(fn ($p) => in_array('outdated', $p->command, true), 1);

    $this->brew->outdatedPhp(fresh: true);
    Process::assertRanTimes(fn ($p) => in_array('outdated', $p->command, true), 2);
});

it('builds install and upgrade plans', function () {
    $bin = $this->brewFs->root.'/bin/brew';

    expect($this->brew->installPlan('8.1'))->toBe([
        'label' => 'brew install shivammathur/php/php@8.1',
        'argv' => [$bin, 'install', 'shivammathur/php/php@8.1'],
        'cwd' => null,
        'timeout' => 1800,
    ])->and($this->brew->upgradePlan('php@8.4')['argv'])->toBe([$bin, 'upgrade', 'php@8.4'])
      ->and(fn () => $this->brew->installPlan('8'))->toThrow(RuntimeException::class);
});
