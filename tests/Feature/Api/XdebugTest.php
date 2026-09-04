<?php

use App\Support\Probe;
use App\Support\Shell;
use App\Support\TaskSpawner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeBrew;
use Tests\Support\FakeValet;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nomeus-xdebugapi-'.uniqid();
    mkdir("{$this->root}/nomeus", 0755, true);
    $this->brewFs = (new FakeBrew)->installed('8.4', '8.4.25')->linked('8.4');
    file_put_contents("{$this->root}/nomeus/config.json", json_encode(['brew_prefix' => $this->brewFs->root]));
    config()->set('nomeus.config_path', "{$this->root}/nomeus/config.json");
    mkdir("{$this->root}/agents", 0755, true);
    config()->set('nomeus.launch_agents_dir', "{$this->root}/agents");   // the detect watcher's plist — never the real ~/Library/LaunchAgents
    config()->set('nomeus.uid', 501);
    $this->valetFs = new FakeValet;
    config()->set('nomeus.valet_config_dir', $this->valetFs->configDir);
    config()->set('nomeus.valet_bin', $this->valetFs->valetBin());
    $this->fpmUp = true;
    $this->mock(Probe::class, function ($m) {
        $m->shouldReceive('tcp')->andReturn(false);
        $m->shouldReceive('unix')->andReturnUsing(fn () => $this->fpmUp);   // valet.sock answers → fpm "back" after a restart
    });
    $this->spawned = [];
    $this->mock(TaskSpawner::class, fn ($m) => $m->shouldReceive('spawn')->andReturnUsing(function (string $cmd) {
        $this->spawned[] = $cmd;
    }));
    $so = $this->brewFs->root.'/opt/xdebug@8.4/xdebug.so';
    mkdir(dirname($so), 0755, true);
    file_put_contents($so, 'ELF');
    $this->tapIni = $this->brewFs->root.'/etc/php/8.4/conf.d/20-xdebug.ini';
    file_put_contents($this->tapIni, "zend_extension=\"{$so}\"\n");
    Process::fake([
        '*bin/valet*' => Process::result("ok\n"),
        '*pgrep*' => Process::result('', '', 1),
        '*php*-r*' => Process::result('8.4.25'),
        '*launchctl*print-disabled*' => Process::result(''),
        '*launchctl*print*' => Process::result('', '', 113),
        '*launchctl*' => Process::result(''),
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
    $this->brewFs->destroy();
    $this->valetFs->destroy();
});

it('reports per-version status and enqueues install / mode changes as tasks', function () {
    $h = ['X-Nomeus' => '1'];
    $status = $this->getJson('/api/xdebug')->assertOk()->assertJsonPath('data.linked', '8.4')->assertJsonPath('data.port', 9003)->assertJsonPath('data.ide_listening', false)->json('data');
    expect($status['versions']['8.4'])->toMatchArray(['installed' => true, 'mode' => 'off', 'tap_ini' => true]);   // found via the tap ini, not adopted yet

    $php = app(Shell::class)->phpBin();
    $this->postJson('/api/xdebug/install', ['version' => '8.4'], $h)->assertStatus(202)
        ->assertJsonPath('task.argv', [$php, base_path('artisan'), 'xdebug:install', '8.4', '--no-interaction']);
    $this->postJson('/api/xdebug/mode', ['version' => '8.4', 'mode' => 'trigger'], $h)->assertStatus(202)
        ->assertJsonPath('task.argv', [$php, base_path('artisan'), 'xdebug:mode', 'trigger', '--php=8.4', '--no-interaction']);
    $this->postJson('/api/xdebug/mode', ['version' => 'all', 'mode' => 'off'], $h)->assertStatus(202)
        ->assertJsonPath('task.argv', [$php, base_path('artisan'), 'xdebug:mode', 'off', '--all', '--no-interaction']);
    $this->postJson('/api/xdebug/mode', ['version' => '8.4', 'mode' => 'loud'], $h)->assertUnprocessable();
    $this->postJson('/api/xdebug/mode', ['version' => '7.4', 'mode' => 'on'], $h)->assertUnprocessable();
    $this->postJson('/api/xdebug/mode', ['version' => '8.4', 'mode' => 'on'])->assertForbidden();
    expect($this->spawned)->toHaveCount(3);
});

it('drives it from the cli: install adopts the tap ini, mode switches and restarts fpm', function () {
    $this->artisan('xdebug:install')->expectsOutputToContain('xdebug installed, mode off')->assertSuccessful();   // linked php; already present → adopt only
    expect(is_file($this->tapIni))->toBeFalse()->and(is_file($this->tapIni.'.nomeus-off'))->toBeTrue();
    Process::assertNotRan(fn ($p) => ($p->command[1] ?? '') === 'install');

    touch("{$this->valetFs->configDir}/valet.sock");
    $this->artisan('xdebug:mode on')
        ->expectsOutputToContain('php 8.4: xdebug on')
        ->expectsOutputToContain('valet restart php')
        ->expectsOutputToContain('php-fpm back: 8.4')
        ->expectsOutputToContain('nothing is listening')
        ->assertSuccessful();
    Process::assertRan(fn ($p) => $p->command === [$this->valetFs->valetBin(), 'restart', 'php']);
    expect(file_get_contents($this->brewFs->root.'/etc/php/8.4/conf.d/99-nomeus.ini'))->toContain('xdebug.start_with_request=yes');

    $this->artisan('xdebug:mode on --no-restart')->expectsOutputToContain('(ini unchanged)')->assertSuccessful();
    $this->artisan('xdebug:mode trigger --all --no-restart')->expectsOutputToContain('still runs the previous mode')->assertSuccessful();
    $this->artisan('xdebug')->expectsOutputToContain('trigger')->assertSuccessful();
    $this->artisan('xdebug:mode loud')->assertFailed();
});

it('runs detect from the cli: mode, the watcher agent, and xdebug:watch --once', function () {
    $this->artisan('xdebug:install')->assertSuccessful();
    touch("{$this->valetFs->configDir}/valet.sock");

    $this->artisan('xdebug:mode detect --no-restart')
        ->expectsOutputToContain('php 8.4: xdebug detect → off (IDE not listening)')
        ->assertSuccessful();
    $plist = "{$this->root}/agents/dev.nomeus.svc.xdebug-detect.plist";
    expect(is_file($plist))->toBeTrue()->and(file_get_contents($plist))->toContain('xdebug:watch');
    Process::assertRan(fn ($p) => $p->command === ['launchctl', 'bootstrap', 'gui/501', $plist]);

    // --once with the IDE now listening: the ini flips to on and fpm restarts
    $this->mock(Probe::class, function ($m) {
        $m->shouldReceive('tcp')->andReturnUsing(fn ($h, $p) => $p === 9003);
        $m->shouldReceive('unix')->andReturn(true);
    });
    $this->artisan('xdebug:watch --once')
        ->expectsOutputToContain('xdebug → on (IDE listening)')
        ->expectsOutputToContain('valet restart php')
        ->assertSuccessful();
    expect(file_get_contents($this->brewFs->root.'/etc/php/8.4/conf.d/99-nomeus.ini'))->toContain('xdebug.start_with_request=yes');
    $this->artisan('xdebug:watch --once')->expectsOutputToContain('in sync: IDE listening')->assertSuccessful();
    $this->artisan('xdebug')->expectsOutputToContain('detect')->assertSuccessful();
    $v = $this->getJson('/api/xdebug')->assertOk()->json('data.versions')['8.4'];   // "8.4" has a dot: no json-path here
    expect($v)->toMatchArray(['mode' => 'detect', 'effective' => 'on']);

    // any other mode removes the agent
    $this->artisan('xdebug:mode off --no-restart')->assertSuccessful();
    expect(is_file($plist))->toBeFalse();
    Process::assertRan(fn ($p) => $p->command === ['launchctl', 'bootout', 'gui/501/dev.nomeus.svc.xdebug-detect']);
    $this->artisan('xdebug:watch --once')->expectsOutputToContain('no version in detect mode')->assertSuccessful();
});
