<?php

use App\Services\Mcp\ToolRegistry;
use App\Support\Probe;
use App\Support\TaskSpawner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeBrew;
use Tests\Support\FakeValet;

/** Every tool against the fake world, through the container (the way `nomeus mcp` runs). */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nomeus-mcp-'.uniqid();
    mkdir("{$this->root}/nomeus", 0755, true);
    mkdir("{$this->root}/agents", 0755, true);
    $this->brewFs = (new FakeBrew)->formula('redis', '8.2.1', ['redis-server'])->installed('8.4', '8.4.25')->linked('8.4');
    file_put_contents("{$this->root}/nomeus/config.json", json_encode(['brew_prefix' => $this->brewFs->root]));
    config()->set('nomeus.config_path', "{$this->root}/nomeus/config.json");
    config()->set('nomeus.launch_agents_dir', "{$this->root}/agents");
    config()->set('nomeus.uid', 501);
    $this->valetFs = new FakeValet;
    config()->set('nomeus.valet_config_dir', $this->valetFs->configDir);
    config()->set('nomeus.valet_bin', $this->valetFs->valetBin());
    $smoke = $this->valetFs->parked('smoke', laravel: true);
    File::ensureDirectoryExists("$smoke/storage/logs");
    file_put_contents("$smoke/storage/logs/laravel.log", "[2026-09-03 10:00:00] local.INFO: hello\n[2026-09-03 10:00:01] local.ERROR: it broke\n#0 /x.php(1): y()\n");
    file_put_contents("$smoke/.env", "APP_KEY=secret\nDB_CONNECTION=pgsql\nSESSION_DRIVER=redis\nDB_PASSWORD=hunter2\n");
    file_put_contents("$smoke/nomeus.yml", "services:\n  - { type: redis }\n");
    touch("{$this->valetFs->configDir}/valet.sock");

    $this->answering = [];
    $this->mock(Probe::class, function ($m) {
        $m->shouldReceive('tcp')->andReturnUsing(fn (string $h, int $p) => in_array($p, $this->answering, true));
        $m->shouldReceive('unix')->andReturn(true);
    });
    $this->mock(TaskSpawner::class, fn ($m) => $m->shouldReceive('spawn'));
    Process::fake([
        '*launchctl*print-disabled*' => Process::result(''),
        '*launchctl*print*gui/501/*' => Process::result('', '', 113),
        '*launchctl*print*' => Process::result("gui/501 = {}\n"),
        "*'launchctl' 'list'*" => Process::result(''),
        '*launchctl*bootstrap*' => function ($p) {
            $name = substr(basename($p->command[3], '.plist'), strlen(\App\Services\LaunchdManager::PREFIX));
            $this->answering[] = app(\App\Services\ServiceManager::class)->find($name)?->port ?? 0;

            return Process::result('');
        },
        '*launchctl*bootout*' => function () { $this->answering = []; return Process::result(''); },
        '*launchctl*' => Process::result(''),
        '*--version*' => Process::result("stub 1.0\n"),
        "*php' '-m'*" => Process::result("[PHP Modules]\nCore\nredis\n"),
        '*php*-r*' => Process::result('8.4.25'),
        '*lsof*' => Process::result("node 123 owldesign TCP 127.0.0.1:5173 (LISTEN)\n"),
        '*pgrep*' => Process::result('', '', 1),
        '*which*' => Process::result(''),
        '*git*' => Process::result(''),
        '*brew*outdated*' => Process::result(json_encode(['formulae' => []])),
        '*bin/valet*' => Process::result("ok\n"),
    ]);
    $this->tools = app(ToolRegistry::class);
    $this->call = fn (string $name, array $args = []) => $this->tools->call($name, $args);
});

afterEach(function () {
    File::deleteDirectory($this->root);
    $this->brewFs->destroy();
    $this->valetFs->destroy();
});

it('exposes the read tools against the fake world', function () {
    expect($this->tools->names())->toContain('list_sites', 'whats_on_port', 'set_xdebug', 'doctor', 'init_plan')
        ->and(count($this->tools->names()))->toBe(21);

    $sites = ($this->call)('list_sites');
    expect($sites[0]['name'])->toBe('smoke')->and($sites[0]['manifest'])->toBeTrue();
    expect(($this->call)('site_env_keys', ['name' => 'smoke']))->toBe([
        'env' => true,
        'keys' => ['APP_KEY', 'DB_CONNECTION', 'SESSION_DRIVER', 'DB_PASSWORD'],
        'drivers' => ['DB_CONNECTION' => 'pgsql', 'SESSION_DRIVER' => 'redis'],    // driver names only; APP_KEY and DB_PASSWORD never appear as values
    ]);
    Process::fake(['*artisan*about*' => Process::result('', 'boom: no database', 1)]);
    expect(($this->call)('site_info', ['name' => 'smoke']))->toMatchArray(['about' => null, 'about_error' => 'boom: no database'])
        ->and(($this->call)('site_info', ['name' => 'smoke'])['manifest_yaml'])->toContain('type: redis');
    expect(fn () => ($this->call)('site_info', ['name' => 'nope']))->toThrow(RuntimeException::class, 'No site [nope]');

    $log = ($this->call)('tail_log', ['source' => 'smoke', 'level' => 'error']);
    expect($log['entries'])->toHaveCount(1)->and($log['entries'][0]['message'])->toBe('it broke');
    expect(fn () => ($this->call)('tail_log', ['source' => 'nope']))->toThrow(RuntimeException::class, 'No log for [nope]');

    expect(($this->call)('whats_on_port', ['port' => 5173]))->toMatchArray(['instance' => null])->and(($this->call)('whats_on_port', ['port' => 5173])['lsof'])->toContain('node');
    expect(($this->call)('php_versions')[0]['version'])->toBe('8.4');
    expect(($this->call)('xdebug_status')['ide_listening'])->toBeFalse();
    expect(($this->call)('doctor', ['section' => 'mail'])['rows'][0]['section'])->toBe('mail');
    expect(($this->call)('init_plan', ['name' => 'smoke'])[0]['id'])->toBe('site');
    expect(($this->call)('recent_dumps'))->toBe([]);
    expect(($this->call)('set_capture', ['on' => true]))->toBe(['capture' => true]);
    expect(($this->call)('list_tasks'))->toBe([]);
    expect(fn () => ($this->call)('service_status', []))->toThrow(InvalidArgumentException::class, 'missing required argument [name]');
});

it('starts, reports and stops a service instance', function () {
    app(\App\Services\ServiceManager::class)->create('redis', start: false);

    expect(($this->call)('list_services')[0]['name'])->toBe('redis');
    expect(($this->call)('whats_on_port', ['port' => 6379]))->toBe(['instance' => 'redis', 'type' => 'redis', 'main_port' => true]);
    expect(($this->call)('start_service', ['name' => 'redis'])['status']['running'])->toBeTrue();
    expect(($this->call)('service_status', ['name' => 'redis'])['env'])->toContain('REDIS_PORT=6379');
    expect(($this->call)('stop_service', ['name' => 'redis'])['status']['running'])->toBeFalse();
    expect(fn () => ($this->call)('restart_service', ['name' => 'nope']))->toThrow(RuntimeException::class, 'No service instance [nope]');
});

it('runs from the cli: --list and --call', function () {
    $this->artisan('mcp --list')->expectsOutputToContain('whats_on_port')->assertSuccessful();
    $this->artisan('mcp --call=list_sites')->expectsOutputToContain('"smoke"')->assertSuccessful();
    $this->artisan('mcp', ['--call' => 'site_info', '--args' => '{"name":"nope"}'])->expectsOutputToContain('No site [nope]')->assertFailed();
    $this->artisan('mcp', ['--call' => 'site_info', '--args' => 'notjson'])->expectsOutputToContain('JSON object')->assertFailed();
});

it('prints or writes the client registration', function () {
    $this->artisan('mcp:install claude')->expectsOutputToContain('claude_desktop_config.json')->expectsOutputToContain('"args": [')->assertSuccessful();
    $this->artisan('mcp:install code')->expectsOutputToContain('claude mcp add nomeus --')->assertSuccessful();
    $this->artisan('mcp:install vim')->expectsOutputToContain('claude, code or cursor')->assertFailed();
});
