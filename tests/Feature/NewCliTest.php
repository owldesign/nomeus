<?php

use App\Support\Probe;
use App\Support\TaskSpawner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeBrew;
use Tests\Support\FakeValet;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nomeus-newcli-'.uniqid();
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
    $this->parked = $this->valetFs->sitesRoot;
    $this->valetFs->parked('existing', laravel: true);

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
        '*composer*create-project*' => function ($p) {
            $dir = $p->path.'/'.end($p->command);
            mkdir($dir, 0755, true);
            file_put_contents("$dir/artisan", "#!/usr/bin/env php\n<?php\n");
            file_put_contents("$dir/.env.example", "APP_NAME=Laravel\nMAIL_MAILER=log\n");

            return Process::result("Installing laravel/laravel\n");
        },
        "*'composer'*" => Process::result("ok\n"),
        '*bin/valet*' => Process::result("ok\n"),
        "*'sh' '-c'*" => Process::result("ok\n"),
        "*'open' *" => Process::result(''),
        '*pgrep*' => Process::result('', '', 1),
        '*php*-r*' => Process::result('8.4.25'),
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
    $this->brewFs->destroy();
    $this->valetFs->destroy();
});

it('prints the manifest and the init plan with --dry-run and creates nothing', function () {
    // Artisan::call keeps the real console output (the test harness's $this->artisan() replaces it with a mock).
    $exit = \Illuminate\Support\Facades\Artisan::call('new shop --laravel --db=none --redis --mail --secure --php=8.4 --yes --dry-run');
    $out = \Illuminate\Support\Facades\Artisan::output();

    expect($exit)->toBe(0)
        ->and($out)->toContain("{$this->parked}/shop/nomeus.yml")
        ->and($out)->toContain("domain: shop\n")
        ->and($out)->toMatch("/^php: '?8\\.4'?$/m")                       // quoted or not, the dumper's call
        ->and($out)->toContain('  - { type: redis }')
        ->and($out)->toContain('post-init:')
        ->and($out)->toContain('would run: composer create-project laravel/laravel')
        ->and($out)->toContain('link shop.test');
    expect(is_dir("{$this->parked}/shop"))->toBeFalse();
    Process::assertNotRan(fn ($p) => in_array('create-project', $p->command ?? [], true));
});

it('scaffolds, writes nomeus.yml, and runs init', function () {
    $exit = \Illuminate\Support\Facades\Artisan::call('new shop --laravel --db=none --redis --mail --secure --yes --no-scripts --open');
    $out = \Illuminate\Support\Facades\Artisan::output();
    expect($exit)->toBe(0)
        ->and($out)->toContain('composer create-project laravel/laravel shop')
        ->and($out)->toContain('nomeus.yml written')
        ->and($out)->toContain('https://shop.test —');

    $dir = "{$this->parked}/shop";
    expect(is_file("$dir/nomeus.yml"))->toBeTrue()
        ->and(file_get_contents("$dir/nomeus.yml"))->toContain('php artisan migrate --force')
        ->and(file_get_contents("$dir/.env"))->toContain('APP_URL=https://shop.test')
        ->and(file_get_contents("$dir/.env"))->toContain('MAIL_MAILER=smtp')
        ->and(file_get_contents("$dir/.env"))->toContain('QUEUE_CONNECTION=redis')
        ->and(app(\App\Services\ServiceManager::class)->find('redis'))->not->toBeNull()
        ->and(app(\App\Services\ServiceManager::class)->find('mailpit'))->not->toBeNull();
    Process::assertRan(fn ($p) => $p->command === [$this->valetFs->valetBin(), 'secure', 'shop']);
    Process::assertNotRan(fn ($p) => $p->command === [$this->valetFs->valetBin(), 'link', 'shop']);   // under the parked dir: already a site
    Process::assertNotRan(fn ($p) => ($p->command[0] ?? '') === 'sh');                               // --no-scripts
    Process::assertRan(fn ($p) => $p->command === ['open', 'https://shop.test']);
});

it('refuses bad names, an existing site, unknown databases, and missing php versions', function () {
    $this->artisan('new Shop --yes')->expectsOutputToContain('lowercase')->assertFailed();
    $this->artisan('new existing --laravel --yes')->expectsOutputToContain('already exists')->assertFailed();
    $this->artisan('new shop --laravel --db=mongo --yes')->expectsOutputToContain('--db must be')->assertFailed();
    $this->artisan('new shop --laravel --php=7.4 --yes')->expectsOutputToContain('php 7.4 is not installed')->assertFailed();
    $this->artisan('new shop --laravel --service=kafka --yes')->expectsOutputToContain('Unknown service type')->assertFailed();
});

it('enqueues nomeus new from the api', function () {
    $php = app(\App\Support\Shell::class)->phpBin();
    $this->postJson('/api/sites/new', ['name' => 'shop', 'starter' => 'from', 'from' => 'laravel/laravel:^12', 'php' => '8.4', 'db' => 'postgresql', 'redis' => true, 'services' => ['meilisearch'], 'mail' => true, 'secure' => true, 'skip_scripts' => true], ['X-Nomeus' => '1'])
        ->assertStatus(202)
        ->assertJsonPath('task.label', 'new shop')
        ->assertJsonPath('task.argv', [$php, base_path('artisan'), 'new', 'shop', '--yes', '--from=laravel/laravel:^12', '--php=8.4', '--db=postgresql', '--redis', '--mail', '--secure', '--service=meilisearch', '--no-scripts', '--no-interaction']);
    $this->postJson('/api/sites/new', ['name' => 'shop', 'starter' => 'empty'], ['X-Nomeus' => '1'])->assertStatus(202)
        ->assertJsonPath('task.argv', [$php, base_path('artisan'), 'new', 'shop', '--yes', '--empty', '--no-interaction']);
    $this->postJson('/api/sites/new', ['name' => 'Bad Name'], ['X-Nomeus' => '1'])->assertUnprocessable();
    $this->postJson('/api/sites/new', ['name' => 'shop'])->assertForbidden();
});
