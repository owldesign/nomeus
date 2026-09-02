<?php

use App\Services\Dumps\DumpStore;
use App\Support\Probe;
use App\Support\TaskSpawner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeBrew;
use Tests\Support\FakeValet;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/devkit-doctor-'.uniqid();
    mkdir("{$this->root}/devkit", 0755, true);
    mkdir("{$this->root}/agents", 0755, true);
    $this->brewFs = (new FakeBrew)->installed('8.4', '8.4.25')->linked('8.4')->formula('redis', '8.2.1', ['redis-server']);
    file_put_contents("{$this->root}/devkit/config.json", json_encode(['brew_prefix' => $this->brewFs->root, 'code_dir' => "{$this->root}/code"]));
    config()->set('devkit.config_path', "{$this->root}/devkit/config.json");
    config()->set('devkit.launch_agents_dir', "{$this->root}/agents");
    config()->set('devkit.uid', 501);
    $this->valetFs = new FakeValet;
    config()->set('devkit.valet_config_dir', $this->valetFs->configDir);
    config()->set('devkit.valet_bin', $this->valetFs->valetBin());
    $this->valetFs->parked('smoke', laravel: true);
    touch("{$this->valetFs->configDir}/valet.sock");

    $this->mock(Probe::class, function ($m) {
        $m->shouldReceive('tcp')->andReturn(false);
        $m->shouldReceive('unix')->andReturn(true);
    });
    $this->spawned = [];
    $this->mock(TaskSpawner::class, fn ($m) => $m->shouldReceive('spawn')->andReturnUsing(function (string $cmd) { $this->spawned[] = $cmd; }));
    Process::fake([
        '*launchctl*print-disabled*' => Process::result(''),
        '*launchctl*print*' => Process::result("gui/501 = {}\n"),
        "*'launchctl' 'list'*" => Process::result(''),
        '*launchctl*' => Process::result(''),
        '*pgrep*-x*nginx*' => Process::result('123'),
        '*pgrep*-x*dnsmasq*' => Process::result('124'),
        '*pgrep*' => Process::result('', '', 1),
        '*which*' => Process::result(''),                         // no devkit shim on PATH → warn
        '*brew*outdated*' => Process::result(json_encode(['formulae' => []])),
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

    expect($r['sections'])->toBe(['valet', 'php', 'devkit', 'services', 'dumps', 'mail', 'retention'])
        ->and($rows['valet|installed']['level'])->toBe('ok')
        ->and($rows['valet|nginx']['level'])->toBe('ok')                 // via the pgrep fallback (probe is mocked false)
        ->and($rows['valet|trusted']['level'])->toBe(is_file('/etc/sudoers.d/valet') ? 'ok' : 'fail')   // the real machine's sudoers
        ->and($rows['php|linked']['detail'])->toContain('php 8.4')
        ->and($rows['php|php-fpm']['detail'])->toBe('running: 8.4')
        ->and($rows['php|99-devkit.ini php 8.4']['level'])->toBe('warn')
        ->and($rows['devkit|config']['level'])->toBe('ok')
        ->and($rows['devkit|bin/devkit']['level'])->toBe('warn')
        ->and($rows['dumps|server']['detail'])->toContain('services:create dumps')
        ->and($rows['mail|mailpit']['detail'])->toContain('devkit mail --create')
        ->and($rows['retention|tasks']['level'])->toBe('ok')
        ->and($r['counts']['ok'])->toBeGreaterThan(5);

    $this->getJson('/api/doctor?section=mail')->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.section', 'mail');
});

it('clears in the cli: doctor sections, json, exit code', function () {
    $this->artisan('doctor --section=mail')->expectsOutputToContain('devkit mail --create')->assertSuccessful();   // warn only → 0
    $this->artisan('doctor --section=nope')->expectsOutputToContain('Sections:')->assertFailed();
    $this->artisan('doctor --json --section=retention')->expectsOutputToContain('"section": "retention"')->assertSuccessful();
    // a fail row (dashboard not linked in the fake valet) makes the exit code non-zero
    $this->artisan('doctor --section=devkit')->expectsOutputToContain('not linked')->assertFailed();
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

it('enqueues self-update as a task from the dashboard', function () {
    $php = app(\App\Support\Shell::class)->phpBin();
    $this->postJson('/api/update', ['check' => true], ['X-Devkit' => '1'])->assertStatus(202)
        ->assertJsonPath('task.argv', [$php, base_path('artisan'), 'self-update', '--check', '--no-interaction']);
    $this->postJson('/api/update', ['no_build' => true], ['X-Devkit' => '1'])->assertStatus(202)
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
        ->and($md)->toContain('- `--section` — valet, php, devkit, services, dumps, mail, retention');

    $i = app(\App\Services\ServiceManager::class)->create('redis', start: false);
    file_put_contents($i->logFile(), str_repeat("noise\n", 1000));
    $this->artisan('services:logs redis --clear')->expectsOutputToContain('logs cleared (6 KB)')->assertSuccessful();
    expect(filesize($i->logFile()))->toBe(0);

    app(DumpStore::class)->insert(['kind' => 'dump', 'request_key' => null, 'uri' => null, 'method' => null, 'command' => null, 'file' => null, 'line' => null, 'text' => 'x', 'html' => '', 'payload' => null]);
    $this->artisan('doctor --section=retention')->expectsOutputToContain('newest 5000 rows')->assertSuccessful();
});
