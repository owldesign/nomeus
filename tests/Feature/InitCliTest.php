<?php

use App\Support\Probe;
use App\Support\TaskSpawner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeBrew;
use Tests\Support\FakeValet;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nomeus-initcli-'.uniqid();
    mkdir("{$this->root}/nomeus", 0755, true);
    mkdir("{$this->root}/agents", 0755, true);
    $this->brewFs = (new FakeBrew)->formula('redis', '8.2.1', ['redis-server'])->formula('mailpit', '1.31.0', ['mailpit'])->installed('8.4', '8.4.25')->linked('8.4');
    file_put_contents("{$this->root}/nomeus/config.json", json_encode(['brew_prefix' => $this->brewFs->root]));
    config()->set('nomeus.config_path', "{$this->root}/nomeus/config.json");
    config()->set('nomeus.launch_agents_dir', "{$this->root}/agents");
    config()->set('nomeus.uid', 501);
    $this->valetFs = new FakeValet;
    config()->set('nomeus.valet_config_dir', $this->valetFs->configDir);
    config()->set('nomeus.valet_bin', $this->valetFs->valetBin());
    $this->site = realpath($this->valetFs->parked('smoke', laravel: true));
    file_put_contents("{$this->site}/.env", "APP_NAME=Laravel\n");

    $this->answering = [];
    $this->mock(Probe::class, function ($m) {
        $m->shouldReceive('tcp')->andReturnUsing(fn (string $h, int $p) => in_array($p, $this->answering, true));
        $m->shouldReceive('unix')->andReturn(false);
    });
    $this->spawned = [];
    $this->mock(TaskSpawner::class, fn ($m) => $m->shouldReceive('spawn')->andReturnUsing(function (string $cmd) { $this->spawned[] = $cmd; }));
    Process::fake([
        '*launchctl*print-disabled*' => Process::result(''),
        '*launchctl*print*' => Process::result('', '', 113),
        "*'launchctl' 'list'*" => Process::result(''),
        '*launchctl*bootstrap*' => function ($p) {
            $name = substr(basename($p->command[3], '.plist'), strlen(\App\Services\LaunchdManager::PREFIX));
            $this->answering[] = app(\App\Services\ServiceManager::class)->find($name)?->port ?? 0;

            return Process::result('');
        },
        '*launchctl*' => Process::result(''),
        "*php' '-m'*" => Process::result("[PHP Modules]\nCore\nredis\n\n[Zend Modules]\n"),   // init's redis-extension check
        '*which*' => Process::result(''),                          // no fnm on PATH in the fake world
        '*--version*' => Process::result("stub 1.0\n"),
        "*mailpit' 'version*" => Process::result("mailpit v1.31.0\n"),
        '*bin/valet*' => Process::result("ok\n"),
        "*'composer'*" => Process::result("ok\n"),
        "*'sh' '-c'*" => Process::result("ok\n"),
        '*pgrep*' => Process::result('', '', 1),
        '*php*-r*' => Process::result('8.4.25'),
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
    $this->brewFs->destroy();
    $this->valetFs->destroy();
});

it('shows the plan with --dry-run and changes nothing', function () {
    file_put_contents("{$this->site}/nomeus.yml", "domain: smoke\nsecure: true\nservices:\n  - type: redis\nmail: true\npost-init:\n  - composer install\n");

    $this->artisan("init {$this->site} --dry-run")
        ->expectsOutputToContain('site smoke.test')
        ->expectsOutputToContain('create redis')
        ->expectsOutputToContain('create mailpit')
        ->assertSuccessful();
    expect(app(\App\Services\ServiceManager::class)->all())->toBe([])
        ->and(file_get_contents("{$this->site}/.env"))->toBe("APP_NAME=Laravel\n");
    Process::assertNotRan(fn ($p) => str_contains($p->command[0] ?? '', 'valet'));
});

it('runs init, skipping scripts on request', function () {
    file_put_contents("{$this->site}/nomeus.yml", "domain: smoke\nname: smoke\nservices:\n  - type: redis\nmail: true\nenv:\n  CACHE_STORE: redis\npost-init:\n  - composer install\n");

    $this->artisan("init {$this->site} --skip-scripts")
        ->expectsOutputToContain('smoke.test ready')
        ->assertSuccessful();

    $env = file_get_contents("{$this->site}/.env");
    expect($env)->toContain('APP_URL=http://smoke.test')->and($env)->toContain('REDIS_HOST=127.0.0.1')->and($env)->toContain('MAIL_PORT=1025')->and($env)->toContain('CACHE_STORE=redis')
        ->and(app(\App\Services\ServiceManager::class)->find('redis'))->not->toBeNull()
        ->and(app(\App\Services\ServiceManager::class)->find('mailpit'))->not->toBeNull();
    Process::assertNotRan(fn ($p) => ($p->command[0] ?? '') === 'sh');
    Process::assertRan(fn ($p) => ($p->command[0] ?? '') === 'composer' && ($p->command[1] ?? '') === 'require');
});

it('explains a missing manifest', function () {
    $this->artisan("init {$this->site}")->expectsOutputToContain('No nomeus.yml')->assertFailed();
});

it('enqueues init from the api for sites with a manifest', function () {
    $h = ['X-Nomeus' => '1'];
    $this->postJson('/api/sites/smoke/init', [], $h)->assertUnprocessable();

    file_put_contents("{$this->site}/nomeus.yml", "domain: smoke\n");
    $this->getJson('/api/sites')->assertOk()->assertJsonPath('data.0.manifest', true);
    $this->postJson('/api/sites/smoke/init', ['skip_scripts' => true], $h)->assertStatus(202)
        ->assertJsonPath('task.label', 'init smoke')
        ->assertJsonPath('task.argv', [app(\App\Support\Shell::class)->phpBin(), base_path('artisan'), 'init', $this->site, '--skip-scripts', '--no-interaction']);   // the fake brew's linked php
});
