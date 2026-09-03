<?php

use App\Services\Dumps\DumpStore;
use App\Support\Probe;
use App\Support\TaskSpawner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Tests\Support\FakeBrew;
use Tests\Support\FakeValet;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nomeus-dumpsapi-'.uniqid();
    mkdir("{$this->root}/nomeus", 0755, true);
    mkdir("{$this->root}/agents", 0755, true);
    $this->brewFs = (new FakeBrew)->installed('8.4', '8.4.25')->linked('8.4');
    file_put_contents("{$this->root}/nomeus/config.json", json_encode(['brew_prefix' => $this->brewFs->root, 'ide' => 'phpstorm']));
    config()->set('nomeus.config_path', "{$this->root}/nomeus/config.json");
    config()->set('nomeus.launch_agents_dir', "{$this->root}/agents");
    config()->set('nomeus.uid', 501);
    $this->valetFs = new FakeValet;
    config()->set('nomeus.valet_config_dir', $this->valetFs->configDir);
    config()->set('nomeus.valet_bin', $this->valetFs->valetBin());
    $this->up = false;
    $this->mock(Probe::class, function ($m) {
        $m->shouldReceive('tcp')->andReturnUsing(fn (string $h, int $p) => $p === 9912 && $this->up);
        $m->shouldReceive('unix')->andReturn(false);
    });
    $this->mock(TaskSpawner::class, fn ($m) => $m->shouldReceive('spawn'));
    Process::fake([
        '*launchctl*print-disabled*' => Process::result(''),
        '*launchctl*print*' => Process::result('', '', 113),
        "*'launchctl' 'list'*" => Process::result(''),
        '*launchctl*bootstrap*' => function () { $this->up = true; return Process::result(''); },
        '*launchctl*' => Process::result(''),
        '*--version*' => Process::result("PHP 8.4.25\n"),
        '*bin/valet*' => Process::result("ok\n"),
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
    $this->brewFs->destroy();
    $this->valetFs->destroy();
});

it('reports status, toggles capture, serves the header, pages entries with ide links, and clears', function () {
    $h = ['X-Nomeus' => '1'];
    $status = $this->getJson('/api/dumps/status')->assertOk()
        ->assertJsonPath('data.capture', false)->assertJsonPath('data.instance', null)->assertJsonPath('data.prepend', false)
        ->json('data');
    expect($status['ini'])->toBe(['8.4' => ['ini' => false, 'current' => false]]);   // "8.4" has a dot: not addressable with assertJsonPath

    $this->artisan('dumps:install')->expectsOutputToContain('php 8.4')->assertSuccessful();
    $this->postJson('/api/dumps/capture', ['on' => true], $h)->assertOk()->assertJsonPath('capture', true);
    $status = $this->getJson('/api/dumps/status')->assertOk()->assertJsonPath('data.capture', true)->assertJsonPath('data.prepend', true)->json('data');
    expect($status['ini']['8.4']['current'])->toBeTrue();
    $this->postJson('/api/dumps/capture', ['on' => false])->assertForbidden();

    $this->getJson('/api/dumps/header')->assertOk()->assertJsonPath('header', fn ($h) => str_contains($h, 'Sfdump'));

    $store = app(DumpStore::class);
    $ingest = app(\App\Services\Dumps\DumpIngest::class);
    $store->insert($ingest->toRow((new VarCloner)->cloneVar(['a' => 1]), ['source' => ['file' => '/Users/me/routes/web.php', 'line' => 9], 'request' => ['identifier' => 'r1', 'uri' => '/', 'method' => 'GET']]));
    $store->insert(['kind' => 'query', 'request_key' => 'r1', 'uri' => '/', 'method' => 'GET', 'command' => null, 'file' => null, 'line' => null, 'text' => 'select 1', 'html' => null, 'payload' => json_encode(['sql' => 'select 1', 'ms' => 0.3])]);

    $first = $this->getJson('/api/dumps')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.kind', 'dump')
        ->assertJsonPath('data.0.url', 'phpstorm://open?file='.rawurlencode('/Users/me/routes/web.php').'&line=9')
        ->assertJsonPath('data.1.payload.sql', 'select 1')
        ->assertJsonPath('counts.dump', 1)
        ->json('data');
    $this->getJson('/api/dumps?kind=query')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/dumps?after='.$first[1]['id'])->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/dumps/requests')->assertOk()->assertJsonPath('data.0.request_key', 'r1')->assertJsonPath('data.0.n', 2);
    $this->getJson('/api/dumps/status')->assertJsonPath('data.latest_request', 'r1')->assertJsonPath('data.counts.query', 1);

    $this->deleteJson('/api/dumps')->assertForbidden();
    $this->deleteJson('/api/dumps', [], $h)->assertOk()->assertJsonPath('cleared', 2);
});

it('creates the dump server as a nomeus-bound service instance', function () {
    $this->artisan('services:create dumps')->expectsOutputToContain('starting dumps on 127.0.0.1:9912')->assertSuccessful();
    $i = app(\App\Services\ServiceManager::class)->find('dumps');
    expect($i->formula)->toBe('nomeus/dumps')
        ->and($i->options['site_path'])->toBe(base_path())
        ->and($i->options['php_bin_dir'])->toBe($this->brewFs->root.'/bin');
    $plist = file_get_contents("{$this->root}/agents/dev.nomeus.svc.dumps.plist");
    expect($plist)->toContain('<string>dumps:serve</string>')->and($plist)->toContain('<string>--port=9912</string>')
        ->and($plist)->toContain('<key>WorkingDirectory</key>'."\n    <string>".base_path().'</string>');
    $this->getJson('/api/dumps/status')->assertOk()->assertJsonPath('data.instance', 'dumps')->assertJsonPath('data.running', true);
    $this->artisan('services:clone dumps dumps-2')->expectsOutputToContain('not a data service')->assertFailed();
});

it('drives capture and clear from the cli', function () {
    $this->artisan('dumps:capture on')->expectsOutputToContain('capture on')->assertSuccessful();
    expect(is_file("{$this->root}/nomeus/dumps/capture"))->toBeTrue();
    $this->artisan('dumps:capture off')->expectsOutputToContain('capture off')->assertSuccessful();
    $this->artisan('dumps:capture maybe')->assertFailed();
    app(DumpStore::class)->insert(['kind' => 'dump', 'request_key' => null, 'uri' => null, 'method' => null, 'command' => 'artisan tinker', 'file' => null, 'line' => null, 'text' => '"hello"', 'html' => '', 'payload' => null]);
    $this->artisan('dumps --lines=5')->expectsOutputToContain('"hello"')->assertSuccessful();
    $this->artisan('dumps:clear')->expectsOutputToContain('1 entries cleared')->assertSuccessful();
});
