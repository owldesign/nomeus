<?php

use App\Support\Probe;
use App\Support\TaskSpawner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeBrew;
use Tests\Support\FakeValet;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nomeus-node-'.uniqid();
    mkdir("{$this->root}/nomeus", 0755, true);
    $this->brewFs = (new FakeBrew)->installed('8.4', '8.4.25')->linked('8.4');
    file_put_contents("{$this->root}/nomeus/config.json", json_encode(['brew_prefix' => $this->brewFs->root]));
    config()->set('nomeus.config_path', "{$this->root}/nomeus/config.json");
    $this->valetFs = new FakeValet;
    config()->set('nomeus.valet_config_dir', $this->valetFs->configDir);
    config()->set('nomeus.valet_bin', $this->valetFs->valetBin());
    $this->site = realpath($this->valetFs->parked('smoke', laravel: true));
    file_put_contents("{$this->site}/.nvmrc", "18\n");
    $this->fnm = $this->brewFs->root.'/bin/fnm';
    file_put_contents($this->fnm, "#!/bin/sh\n");
    chmod($this->fnm, 0755);
    $this->versions = ['22.11.0 default'];
    $this->mock(Probe::class, function ($m) { $m->shouldReceive('tcp')->andReturn(false); $m->shouldReceive('unix')->andReturn(false); });
    $this->spawned = [];
    $this->mock(TaskSpawner::class, fn ($m) => $m->shouldReceive('spawn')->andReturnUsing(function (string $cmd) { $this->spawned[] = $cmd; }));
    Process::fake([
        "*fnm' 'ls'*" => function () { return Process::result(implode("\n", array_map(fn ($v) => "* v{$v}", $this->versions))."\n"); },
        "*fnm' 'install'*" => function ($p) { $this->versions[] = $p->command[2].'.20.8'; return Process::result(''); },
        "*fnm' 'default'*" => Process::result(''),
        '*--version*' => Process::result("stub 1.0\n"),
        '*php*-r*' => Process::result('8.4.25'),
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
    $this->brewFs->destroy();
    $this->valetFs->destroy();
});

it('shows versions and pins, installs, and pins a site', function () {
    $this->artisan('node')
        ->expectsOutputToContain('node 22.11.0 default')
        ->expectsOutputToContain('nomeus node:install 18')      // smoke pins 18, not installed
        ->assertSuccessful();

    $this->artisan('node:use 18 --site=smoke')
        ->expectsOutputToContain('fnm install 18')
        ->expectsOutputToContain('.nvmrc in')
        ->assertSuccessful();
    Process::assertRan(fn ($p) => $p->command === [$this->fnm, 'install', '18']);
    expect(file_get_contents("{$this->site}/.nvmrc"))->toBe("18\n");
    $this->artisan('node:install 20 --default')->expectsOutputToContain('(default)')->assertSuccessful();
    Process::assertRan(fn ($p) => $p->command === [$this->fnm, 'default', '20.20.8']);
    $this->artisan('node:use 22 --site=nope')->expectsOutputToContain('No site [nope]')->assertFailed();

    $this->getJson('/api/node')->assertOk()->assertJsonPath('data.default', '22.11.0')->assertJsonPath('data.pins.0.site', 'smoke');
    $php = app(\App\Support\Shell::class)->phpBin();
    $this->postJson('/api/node/use', ['version' => '22', 'site' => 'smoke'], ['X-Nomeus' => '1'])->assertStatus(202)
        ->assertJsonPath('task.argv', [$php, base_path('artisan'), 'node:use', '22', '--site=smoke', '--no-interaction']);
    $this->postJson('/api/node/install', ['version' => 'lts', 'default' => true], ['X-Nomeus' => '1'])->assertStatus(202)
        ->assertJsonPath('task.argv', [$php, base_path('artisan'), 'node:install', 'lts', '--default', '--no-interaction']);
    $this->postJson('/api/node/install', ['version' => 'latest;rm'], ['X-Nomeus' => '1'])->assertUnprocessable();
});
