<?php

use App\Services\BrewBridge;
use App\Support\NomeusConfig;
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
    $this->cfg = sys_get_temp_dir().'/nomeus-brewcfg-'.uniqid().'.json';
    file_put_contents($this->cfg, json_encode(['brew_prefix' => $this->brewFs->root]));
    $this->brew = new BrewBridge(new Shell(new NomeusConfig($this->cfg)));
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

it('trusts a formula\'s tap before installing from it, and never for core formulae', function () {
    $bin = $this->brewFs->root.'/bin/brew';

    expect($this->brew->tapOf('typesense/tap/typesense-server'))->toBe('typesense/tap')
        ->and($this->brew->tapOf('redis'))->toBeNull()
        ->and($this->brew->trustTapPlan('typesense/tap/typesense-server'))->toBe(['label' => 'brew trust typesense/tap', 'argv' => [$bin, 'trust', 'typesense/tap'], 'cwd' => null, 'timeout' => 60])
        ->and($this->brew->trustTapPlan('postgresql@17'))->toBeNull();
});

it('screens binaries for loader failures, not for --version support', function () {
    $bin = $this->brewFs->root.'/bin/probe';
    file_put_contents($bin, "#!/bin/sh\n");
    chmod($bin, 0755);

    Process::fake(['*probe*' => Process::result("Typesense 30.2\nInvalid configuration: Data directory is not specified.\n", '', 1)]);
    expect($this->brew->binaryRuns($bin))->toBeNull();                                            // ran; just no --version flag

    Process::fake(['*probe*' => Process::result('', "dyld[1]: Library not loaded: /opt/homebrew/opt/protobuf/lib/libprotobuf-lite.34.0.0.dylib\n  Referenced from: mysqld\n", 134)]);
    expect($this->brew->binaryRuns($bin))->toContain('dyld[1]: Library not loaded');               // crashed in the loader

    Process::fake(['*probe*' => Process::result('', "flag provided but not defined: -version\nSeaweedFS: store billions of files and serve them fast!\nUsage:\n", 2)]);
    expect($this->brew->binaryRuns($bin))->toBeNull();                                            // usage error = it parsed args = it loaded

    Process::fake(['*probe*' => Process::result("weed version 30GB 4.45 darwin arm64\n")]);
    expect($this->brew->binaryRuns($bin, ['version']))->toBeNull();
    Process::assertRan(fn ($p) => $p->command === [$bin, 'version']);

    Process::fake(['*probe*' => Process::result('', "something went wrong\n", 1)]);
    expect($this->brew->binaryRuns($bin))->toBe('something went wrong');                           // ran? unclear — reported

    expect($this->brew->binaryRuns($this->brewFs->root.'/bin/nope'))->toContain('missing or not executable');
});
