<?php

use App\Services\Dumps\DumpStore;
use App\Services\ServiceManager;
use App\Support\Probe;
use App\Support\Shell;
use App\Support\TaskSpawner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeBrew;
use Tests\Support\FakeValet;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nomeus-doctor-'.uniqid();
    mkdir("{$this->root}/nomeus", 0755, true);
    mkdir("{$this->root}/agents", 0755, true);
    $this->brewFs = (new FakeBrew)->installed('8.4', '8.4.25')->linked('8.4')->formula('redis', '8.2.1', ['redis-server']);
    file_put_contents("{$this->root}/nomeus/config.json", json_encode(['brew_prefix' => $this->brewFs->root, 'code_dir' => "{$this->root}/code"]));
    config()->set('nomeus.config_path', "{$this->root}/nomeus/config.json");
    config()->set('nomeus.launch_agents_dir', "{$this->root}/agents");
    config()->set('nomeus.uid', 501);
    $this->valetFs = new FakeValet;
    config()->set('nomeus.valet_config_dir', $this->valetFs->configDir);
    config()->set('nomeus.valet_bin', $this->valetFs->valetBin());
    $smoke = $this->valetFs->parked('smoke', laravel: true);
    file_put_contents("$smoke/.env", "SESSION_DRIVER=redis\n");   // wants phpredis; the fake php has none
    touch("{$this->valetFs->configDir}/valet.sock");

    $this->mock(Probe::class, function ($m) {
        $m->shouldReceive('tcp')->andReturn(false);
        $m->shouldReceive('unix')->andReturn(true);
    });
    $this->spawned = [];
    $this->mock(TaskSpawner::class, fn ($m) => $m->shouldReceive('spawn')->andReturnUsing(function (string $cmd) {
        $this->spawned[] = $cmd;
    }));
    Process::fake([
        '*launchctl*print-disabled*' => Process::result(''),
        '*launchctl*print*' => Process::result("gui/501 = {}\n"),
        "*'launchctl' 'list'*" => Process::result(''),
        '*launchctl*' => Process::result(''),
        '*pgrep*-x*nginx*' => Process::result('123'),
        '*pgrep*-x*dnsmasq*' => Process::result('124'),
        '*pgrep*' => Process::result('', '', 1),
        '*which*' => Process::result(''),                         // no nomeus shim on PATH → warn
        '*brew*outdated*' => Process::result(json_encode(['formulae' => []])),
        "*php' '-m'*" => Process::result("[PHP Modules]\nCore\n"),
        '*--version*' => Process::result("stub 1.0\n"),
        '*php*-r*' => Process::result('8.4.25'),
        '*git*rev-parse*' => Process::result("origin/main\n"),
        '*git*status*' => Process::result(''),
        '*git*fetch*' => Process::result(''),
        '*git*rev-list*HEAD..*' => Process::result("2\n"),
        '*git*rev-list*' => Process::result("0\n"),
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
    $this->brewFs->destroy();
    $this->valetFs->destroy();
});

it('reports every section with a fix for what is missing', function () {
    $r = $this->getJson('/api/doctor')->assertOk()->json('data');
    $rows = collect($r['rows'])->keyBy(fn ($x) => "{$x['section']}|{$x['check']}");

    expect($r['sections'])->toBe(['valet', 'php', 'nomeus', 'services', 'dumps', 'mail', 'retention'])
        ->and($rows['valet|installed']['level'])->toBe('ok')
        ->and($rows['valet|nginx']['level'])->toBe('ok')                 // via the pgrep fallback (probe is mocked false)
        ->and($rows['valet|trusted']['level'])->toBe(is_file('/etc/sudoers.d/valet') ? 'ok' : 'fail')   // the real machine's sudoers
        ->and($rows['php|linked']['detail'])->toContain('php 8.4')
        ->and($rows['php|php-fpm']['detail'])->toBe('running: 8.4')
        ->and($rows['php|99-nomeus.ini php 8.4']['level'])->toBe('warn')
        ->and($rows['nomeus|config']['level'])->toBe('ok')
        ->and($rows['nomeus|bin/nomeus']['level'])->toBe('warn')
        ->and($rows['php|redis ext smoke']['detail'])->toContain('php:ext redis --php=8.4')
        ->and($rows['php|node']['level'])->toBe('warn')                    // no fnm in the fake prefix
        ->and($rows['dumps|server']['detail'])->toContain('services:create dumps')
        ->and($rows['mail|mailpit']['detail'])->toContain('nomeus mail --create')
        ->and($rows['retention|tasks']['level'])->toBe('ok')
        ->and($r['counts']['ok'])->toBeGreaterThan(5);

    $this->getJson('/api/doctor?section=mail')->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.section', 'mail');
});

it('clears in the cli: doctor sections, json, exit code', function () {
    $this->artisan('doctor --section=mail')->expectsOutputToContain('nomeus mail --create')->assertSuccessful();   // warn only → 0
    $this->artisan('doctor --section=nope')->expectsOutputToContain('Sections:')->assertFailed();
    $this->artisan('doctor --json --section=retention')->expectsOutputToContain('"section": "retention"')->assertSuccessful();
    // a fail row (dashboard not linked in the fake valet) makes the exit code non-zero
    $this->artisan('doctor --section=nomeus')->expectsOutputToContain('not linked')->assertFailed();
});

it('reports commits behind with self-update --check and refuses a dirty tree', function () {
    if (! is_dir(base_path('.git'))) {
        $this->markTestSkipped('not a git checkout');
    }
    $this->artisan('self-update --check')->expectsOutputToContain('2 commit(s) behind')->assertSuccessful();

    Process::fake(['*git*status*' => Process::result(" M app/x.php\n")]);
    $this->artisan('self-update')->expectsOutputToContain('Working tree has changes')->assertFailed();
    Process::assertNotRan(fn ($p) => ($p->command[0] ?? '') === 'composer');
});

it('runs npm through fnm whenever fnm is installed (a stray npm without node on PATH is the trap)', function () {
    if (! is_dir(base_path('.git'))) {
        $this->markTestSkipped('not a git checkout');
    }
    $fnm = "{$this->root}/fnm";
    file_put_contents($fnm, "#!/bin/sh\n");
    chmod($fnm, 0755);
    Process::fake([
        '*which*' => fn ($p) => Process::result(in_array('fnm', $p->command, true) ? "{$fnm}\n" : (in_array('npm', $p->command, true) ? "/opt/homebrew/bin/npm\n" : '')),   // npm on PATH, node not, fnm present
        "*fnm' 'ls'*" => Process::result("* v22.11.0 default\n"),
        "*fnm' 'exec'*" => Process::result(''),
        '*git*rev-list*HEAD..*' => Process::result("0\n"),
        "*'composer' 'install'*" => Process::result(''),
    ]);
    $this->artisan('self-update')->expectsOutputToContain('node via fnm (22.11.0)')->run();
    Process::assertRan(fn ($p) => array_slice($p->command, 0, 5) === [$fnm, 'exec', '--using', '22.11.0', '--'] && array_slice($p->command, 5, 2) === ['npm', 'ci']);
    Process::assertRan(fn ($p) => array_slice($p->command, 5) === ['npm', 'run', 'build']);
});

it('enqueues self-update as a task from the dashboard', function () {
    $php = app(Shell::class)->phpBin();
    $this->postJson('/api/update', ['check' => true], ['X-Nomeus' => '1'])->assertStatus(202)
        ->assertJsonPath('task.argv', [$php, base_path('artisan'), 'self-update', '--check', '--no-interaction']);
    $this->postJson('/api/update', ['no_build' => true], ['X-Nomeus' => '1'])->assertStatus(202)
        ->assertJsonPath('task.label', 'self-update')
        ->assertJsonPath('task.argv', [$php, base_path('artisan'), 'self-update', '--no-build', '--no-interaction']);
    $this->postJson('/api/update')->assertForbidden();
});

it('generates the command reference and clears service logs', function () {
    $out = "{$this->root}/commands.md";
    $this->artisan("docs:commands --out={$out}")->expectsOutputToContain('commands)')->assertSuccessful();
    $md = file_get_contents($out);
    expect($md)->toContain('## services')
        ->and($md)->toContain('### `services:create <type> [version] [--name=] [--port=] [--site=] [--no-start]`')
        ->and($md)->toContain('### `doctor [--section=] [--json]`')
        ->and($md)->toContain('- `--section` — valet, php, nomeus, services, dumps, mail, retention');

    $i = app(ServiceManager::class)->create('redis', start: false);
    file_put_contents($i->logFile(), str_repeat("noise\n", 1000));
    $this->artisan('services:logs redis --clear')->expectsOutputToContain('logs cleared (6 KB)')->assertSuccessful();
    expect(filesize($i->logFile()))->toBe(0);

    app(DumpStore::class)->insert(['kind' => 'dump', 'request_key' => null, 'uri' => null, 'method' => null, 'command' => null, 'file' => null, 'line' => null, 'text' => 'x', 'html' => '', 'payload' => null]);
    $this->artisan('doctor --section=retention')->expectsOutputToContain('newest 5000 rows')->assertSuccessful();
});

it('runs only the fixes the doctor proposes, as tasks', function () {
    $php = app(Shell::class)->phpBin();
    $this->postJson('/api/doctor/fix', ['command' => 'nomeus dumps:install --restart'], ['X-Nomeus' => '1'])->assertStatus(202)
        ->assertJsonPath('task.argv', [$php, base_path('artisan'), 'dumps:install', '--restart', '--no-interaction']);
    $this->postJson('/api/doctor/fix', ['command' => 'nomeus php:ext redis --php=8.4'], ['X-Nomeus' => '1'])->assertStatus(202);
    $this->postJson('/api/doctor/fix', ['command' => 'nomeus rm shop --db'], ['X-Nomeus' => '1'])->assertUnprocessable();
    $this->postJson('/api/doctor/fix', ['command' => 'nomeus dumps:install; rm -rf /'], ['X-Nomeus' => '1'])->assertUnprocessable();
    $this->postJson('/api/doctor/fix', ['command' => 'rm -rf /'], ['X-Nomeus' => '1'])->assertUnprocessable();
    $this->postJson('/api/doctor/fix', ['command' => 'nomeus dumps:install'])->assertForbidden();
});

// ── 9b: agents that run a moved checkout ────────────────────────────────────

it('fails the doctor on a dump server agent that points at a checkout that is gone, and agents:rewrite fixes it', function () {
    $services = app(ServiceManager::class);
    $services->create('dumps', name: 'dumps', port: 9912, start: false);
    $plist = "{$this->root}/agents/dev.nomeus.svc.dumps.plist";
    $old = "{$this->root}/Code/devkit";   // never created: the renamed checkout
    file_put_contents($plist, str_replace([base_path('artisan'), '<string>'.base_path().'</string>'], ["{$old}/artisan", "<string>{$old}</string>"], file_get_contents($plist)));

    $rows = collect($this->getJson('/api/doctor?section=nomeus')->assertOk()->json('data.rows'))->keyBy('check');
    expect($rows['agent dumps']['level'])->toBe('fail')
        ->and($rows['agent dumps']['detail'])->toContain("{$old}/artisan is missing")
        ->and($rows['agent dumps']['detail'])->toEndWith('— nomeus agents:rewrite')
        ->and($rows->has('agents'))->toBeFalse();

    $this->artisan('agents:rewrite --dry-run')->expectsOutputToContain('dry run')->assertSuccessful();
    expect(file_get_contents($plist))->toContain("{$old}/artisan");

    // launchd doesn't hold the agent (it was created with --no-start), so the rewrite must not try to bounce it
    Process::fake(['*launchctl*print*' => fn ($p) => $p->command[2] === 'gui/501' ? Process::result("gui/501 = {}\n") : Process::result('', '', 113)]);
    $this->artisan('agents:rewrite')->expectsOutputToContain('1 agent(s) rewritten: dumps')->assertSuccessful();
    Process::assertNotRan(fn ($p) => ($p->command[1] ?? '') === 'bootstrap');
    expect(file_get_contents($plist))->toContain('<string>'.base_path('artisan').'</string>')
        ->and(file_get_contents($plist))->not->toContain($old)
        ->and($services->find('dumps')->options['site_path'])->toBe(base_path());

    $rows = collect($this->getJson('/api/doctor?section=nomeus')->json('data.rows'))->keyBy('check');
    expect($rows['agents']['level'])->toBe('ok')->and($rows['agents']['detail'])->toBe('1 nomeus-bound agent(s) run '.base_path());
    $this->artisan('agents:rewrite')->expectsOutputToContain('✓ dumps — runs this app')->assertSuccessful();

    // the dashboard may run it as a fix
    $php = app(Shell::class)->phpBin();
    $this->postJson('/api/doctor/fix', ['command' => 'nomeus agents:rewrite'], ['X-Nomeus' => '1'])->assertStatus(202)
        ->assertJsonPath('task.argv', [$php, base_path('artisan'), 'agents:rewrite', '--no-interaction']);
});

it('says so when no nomeus-bound agent is installed, and self-update rewrites agents before its doctor', function () {
    $this->artisan('agents:rewrite')->expectsOutputToContain('no nomeus-bound agents installed')->assertSuccessful();
    expect(collect($this->getJson('/api/doctor?section=nomeus')->json('data.rows'))->pluck('check')->all())->not->toContain('agents');

    if (! is_dir(base_path('.git'))) {
        $this->markTestSkipped('not a git checkout');
    }
    Process::fake([
        '*git*rev-list*HEAD..*' => Process::result("0\n"),
        "*'composer' 'install'*" => Process::result(''),
    ]);
    $this->artisan('self-update --no-build')->expectsOutputToContain('▶ agents:rewrite')->expectsOutputToContain('no nomeus-bound agents installed')->run();
});
