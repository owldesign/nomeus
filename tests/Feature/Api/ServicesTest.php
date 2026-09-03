<?php

use App\Services\ServiceManager;
use App\Support\Probe;
use App\Support\TaskSpawner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeBrew;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nomeus-svcapi-'.uniqid();
    mkdir("{$this->root}/nomeus", 0755, true);
    $this->brewFs = (new FakeBrew)->formula('redis', '8.2.1', ['redis-server'])->formula('postgresql@17', '17.6', ['initdb', 'postgres']);
    file_put_contents("{$this->root}/nomeus/config.json", json_encode(['brew_prefix' => $this->brewFs->root]));
    config()->set('nomeus.config_path', "{$this->root}/nomeus/config.json");
    config()->set('nomeus.launch_agents_dir', "{$this->root}/agents");
    config()->set('nomeus.uid', 501);

    $this->mock(Probe::class, function ($m) {
        $m->shouldReceive('tcp')->andReturn(false);
        $m->shouldReceive('unix')->andReturn(false);
    });
    $this->spawned = [];
    $this->mock(TaskSpawner::class, fn ($m) => $m->shouldReceive('spawn')
        ->andReturnUsing(function (string $cmd) { $this->spawned[] = $cmd; }));
    Process::fake([
        '*launchctl*print-disabled*' => Process::result(''),
        '*launchctl*print*' => Process::result('', '', 113),
        "*'launchctl' 'list'*" => Process::result(''),   // exact quoted form: a bare launchctl*list glob also matches "…bootstrap … .plist"; overridden in place by the adoptable test
        '*launchctl*' => Process::result(''),
        '*--version*' => Process::result("stub 1.0\n"),   // driver binary pre-flight
    ]);

    // one existing instance, stopped
    $this->redis = app(ServiceManager::class)->create('redis', start: false);
    file_put_contents($this->redis->logFile(), "ready to accept connections\n");
});

afterEach(function () {
    File::deleteDirectory($this->root);
    $this->brewFs->destroy();
});

$h = ['X-Nomeus' => '1'];
$artisan = fn (array $args) => [PHP_BINARY, base_path('artisan'), ...$args, '--no-interaction'];

it('lists instances with status and env, types with formulae, and detail with a log tail', function () {
    $this->getJson('/api/services')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'redis')
        ->assertJsonPath('data.0.port', 6379)
        ->assertJsonPath('data.0.status.running', false)
        ->assertJsonPath('data.0.status.installed', true)
        ->assertJsonPath('data.0.env.REDIS_PORT', '6379')
        ->assertJsonMissingPath('data.0.log');

    $this->getJson('/api/services/types')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'postgresql')
        ->assertJsonPath('data.0.default_port', 5432)
        ->assertJsonPath('data.0.formulae.0', ['formula' => 'postgresql@17', 'installed' => true, 'version' => '17.6'])
        ->assertJsonPath('data.0.formulae.1.installed', false)
        ->assertJsonPath('data.0.requires_site', false)
        ->assertJsonPath('data.2.type', 'mariadb')
        ->assertJsonPath('data.3.type', 'redis')
        ->assertJsonPath('data.7.type', 'reverb')
        ->assertJsonPath('data.7.requires_site', true)
        ->assertJsonPath('data.7.site_package', 'laravel/reverb');

    $this->getJson('/api/services/redis?lines=20')
        ->assertOk()
        ->assertJsonPath('data.name', 'redis')
        ->assertJsonPath('data.log', fn ($log) => str_contains($log, 'ready to accept connections'));

    $this->getJson('/api/services/nope')->assertNotFound();
});

it('creates through the cli as a task, with validation up front', function () use ($h, $artisan) {
    $this->postJson('/api/services', ['type' => 'postgresql', 'version' => '17', 'name' => 'fsv-pg', 'port' => 5433, 'start' => false], $h)
        ->assertStatus(202)
        ->assertJsonPath('task.label', 'services:create postgresql (fsv-pg)')
        ->assertJsonPath('task.argv', $artisan(['services:create', 'postgresql', '17', '--name=fsv-pg', '--port=5433', '--no-start']))
        ->assertJsonPath('task.cwd', base_path());

    $this->postJson('/api/services', ['type' => 'redis'], $h)->assertStatus(202)
        ->assertJsonPath('task.argv', $artisan(['services:create', 'redis']));
    $this->postJson('/api/services', ['type' => 'reverb', 'site' => 'alpha'], $h)->assertStatus(202)
        ->assertJsonPath('task.argv', $artisan(['services:create', 'reverb', '--site=alpha']));
    $this->postJson('/api/services', ['type' => 'reverb'], $h)->assertUnprocessable()->assertJsonPath('message', 'reverb runs inside a site; pick one.');

    $this->postJson('/api/services', ['type' => 'mongo'], $h)->assertUnprocessable();
    $this->postJson('/api/services', ['type' => 'redis', 'name' => 'Bad Name'], $h)->assertUnprocessable();
    $this->postJson('/api/services', ['type' => 'redis', 'name' => 'redis'], $h)->assertUnprocessable()->assertJsonPath('message', 'Service [redis] already exists.');
    $this->postJson('/api/services', ['type' => 'postgresql', 'version' => '9'], $h)->assertUnprocessable();
    $this->postJson('/api/services', ['type' => 'redis', 'port' => 80], $h)->assertUnprocessable();

    expect($this->spawned)->toHaveCount(3);
    Process::assertNotRan(fn ($p) => is_array($p->command) && in_array('initdb', array_map('basename', $p->command), true));
});

it('runs start, stop, restart, clone and delete as cli tasks', function () use ($h, $artisan) {
    $this->postJson('/api/services/redis/start', [], $h)->assertStatus(202)->assertJsonPath('task.argv', $artisan(['services:start', 'redis']));
    $this->postJson('/api/services/redis/stop', [], $h)->assertStatus(202)->assertJsonPath('task.argv', $artisan(['services:stop', 'redis']));
    $this->postJson('/api/services/redis/restart', [], $h)->assertStatus(202)->assertJsonPath('task.label', 'services:restart redis');

    $this->postJson('/api/services/redis/clone', ['name' => 'redis-copy', 'port' => 6390], $h)->assertStatus(202)
        ->assertJsonPath('task.argv', $artisan(['services:clone', 'redis', 'redis-copy', '--port=6390']));
    $this->postJson('/api/services/redis/clone', ['name' => 'redis'], $h)->assertUnprocessable();
    $this->postJson('/api/services/redis/clone', [], $h)->assertUnprocessable();

    $this->deleteJson('/api/services/redis', [], $h)->assertStatus(202)
        ->assertJsonPath('task.argv', $artisan(['services:delete', 'redis', '--force']));
    $this->deleteJson('/api/services/redis?keep_data=1', [], $h)->assertStatus(202)
        ->assertJsonPath('task.argv', $artisan(['services:delete', 'redis', '--force', '--keep-data']));

    $this->postJson('/api/services/nope/start', [], $h)->assertNotFound();
    expect($this->spawned)->toHaveCount(6);
});

it('refuses unsafe requests without the nomeus header', function () {
    $this->postJson('/api/services/redis/start')->assertForbidden();
    $this->deleteJson('/api/services/redis')->assertForbidden();
    expect($this->spawned)->toBe([]);
});

it('summarises instances in the status snapshot', function () {
    Process::fake(['*php*-r*' => Process::result('8.4.25'), '*pgrep*' => Process::result('', '', 1)]);

    $this->getJson('/api/status')->assertOk()
        ->assertJsonPath('instances', [['name' => 'redis', 'type' => 'redis', 'port' => 6379, 'running' => false]]);
});

it('lists adoptable brew services and enqueues an adoption', function () use ($h, $artisan) {
    $dataDir = $this->brewFs->root.'/var/postgresql@17';
    mkdir($dataDir, 0700, true);
    file_put_contents("{$this->root}/agents/homebrew.mxcl.postgresql@17.plist", '<plist/>');
    Process::fake(["*'launchctl' 'list'*" => Process::result("869\t0\thomebrew.mxcl.postgresql@17\n")]);

    $this->getJson('/api/services/adoptable')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.formula', 'postgresql@17')
        ->assertJsonPath('data.0.type', 'postgresql')
        ->assertJsonPath('data.0.loaded', true)
        ->assertJsonPath('data.0.pid', 869)
        ->assertJsonPath('data.0.port', 5432);

    $this->postJson('/api/services/adopt', ['formula' => 'postgresql@17', 'name' => 'pg17'], $h)->assertStatus(202)
        ->assertJsonPath('task.argv', $artisan(['services:adopt', 'postgresql@17', '--name=pg17']));
    $this->postJson('/api/services/adopt', ['formula' => 'nginx'], $h)->assertUnprocessable();
    $this->postJson('/api/services/adopt', ['formula' => 'redis', 'name' => 'redis'], $h)->assertUnprocessable(); // name taken
    $this->postJson('/api/services/adopt', ['formula' => 'redis'])->assertForbidden();
});
