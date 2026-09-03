<?php

use App\Support\Probe;
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
    $this->valetFs = new FakeValet;
    config()->set('nomeus.valet_config_dir', $this->valetFs->configDir);
    config()->set('nomeus.valet_bin', $this->valetFs->valetBin());
    $this->fpmUp = true;
    $this->mock(Probe::class, function ($m) {
        $m->shouldReceive('tcp')->andReturn(false);
        $m->shouldReceive('unix')->andReturnUsing(fn () => $this->fpmUp);   // valet.sock answers → fpm "back" after a restart
    });
    $this->spawned = [];
    $this->mock(TaskSpawner::class, fn ($m) => $m->shouldReceive('spawn')->andReturnUsing(function (string $cmd) { $this->spawned[] = $cmd; }));
    $so = $this->brewFs->root.'/opt/xdebug@8.4/xdebug.so';
    mkdir(dirname($so), 0755, true);
    file_put_contents($so, 'ELF');
    $this->tapIni = $this->brewFs->root.'/etc/php/8.4/conf.d/20-xdebug.ini';
    file_put_contents($this->tapIni, "zend_extension=\"{$so}\"\n");
    Process::fake([
        '*bin/valet*' => Process::result("ok\n"),
        '*pgrep*' => Process::result('', '', 1),
        '*php*-r*' => Process::result('8.4.25'),
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

    $php = app(\App\Support\Shell::class)->phpBin();
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

    $this->artisan('xdebug:mode on --no-restart')->expectsOutputToContain('(unchanged)')->assertSuccessful();
    $this->artisan('xdebug:mode trigger --all --no-restart')->expectsOutputToContain('still runs the previous mode')->assertSuccessful();
    $this->artisan('xdebug')->expectsOutputToContain('trigger')->assertSuccessful();
    $this->artisan('xdebug:mode loud')->assertFailed();
});
