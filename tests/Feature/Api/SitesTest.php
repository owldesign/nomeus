<?php

use App\Support\Probe;
use App\Support\TaskSpawner;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeValet;

beforeEach(function () {
    $this->fake = new FakeValet;
    config()->set('devkit.config_path', $this->fake->root.'/devkit.json');
    config()->set('devkit.valet_config_dir', $this->fake->configDir);
    config()->set('devkit.valet_bin', $this->fake->valetBin());

    $this->fake->parked('alpha', laravel: true);
    $this->fake->linked('api');
    $this->fake->secured('alpha');
    $this->fake->proxied('grafana', 'http://127.0.0.1:3000');

    $this->mock(Probe::class, function ($m) {
        $m->shouldReceive('tcp')->andReturn(false);
        $m->shouldReceive('unix')->andReturn(false);
    });

    // Mutations spawn detached tasks; capture the shell line instead of launching anything.
    $this->spawned = [];
    $this->mock(TaskSpawner::class, fn ($m) => $m->shouldReceive('spawn')
        ->andReturnUsing(function (string $cmd) { $this->spawned[] = $cmd; }));

    // Process::fake matches patterns in insertion order, so no bare '*' here: a test that
    // re-fakes one of these keys overrides it in place, later keys would never be reached.
    Process::fake([
        '*bin/valet*' => Process::result("done\n"),
        '*php*-r*' => Process::result('8.4.25'),
        '*artisan*about*' => Process::result(''),
        '*pgrep*' => Process::result('', '', 1),
    ]);
});

afterEach(fn () => $this->fake->destroy());

$h = ['X-Devkit' => '1'];

it('lists sites', function () {
    $this->getJson('/api/sites')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.name', 'alpha')
        ->assertJsonPath('data.0.url', 'https://alpha.test')
        ->assertJsonPath('data.0.laravel', true)
        ->assertJsonPath('data.1.type', 'linked')
        ->assertJsonPath('data.2.type', 'proxy')
        ->assertJsonPath('data.2.path', 'http://127.0.0.1:3000');
});

it('shows one site with artisan about for laravel sites', function () use ($h) {
    Process::fake(['*artisan*about*' => Process::result(json_encode([
        'environment' => ['laravel_version' => '12.0.0', 'environment' => 'local'],
        'drivers' => ['cache' => 'file'],
    ]))]);

    $this->getJson('/api/sites/alpha')
        ->assertOk()
        ->assertJsonPath('data.name', 'alpha')
        ->assertJsonPath('data.about.environment.laravel_version', '12.0.0');

    $this->getJson('/api/sites/api')->assertOk()->assertJsonPath('data.about', null);
    $this->getJson('/api/sites/missing')->assertNotFound();
});

it('refuses unsafe requests without the devkit header', function () {
    $this->postJson('/api/sites/alpha/secure')->assertForbidden();
    $this->deleteJson('/api/sites/api/link')->assertForbidden();
    Process::assertNothingRan();
});

it('enqueues secure, isolate, unisolate and unsecure as detached tasks', function () use ($h) {
    $bin = $this->fake->valetBin();

    $r = $this->postJson('/api/sites/alpha/unsecure', [], $h)->assertStatus(202);
    $r->assertJsonPath('task.status', 'queued')->assertJsonPath('task.label', 'valet unsecure alpha')
      ->assertJsonPath('task.argv', [$bin, 'unsecure', 'alpha']);
    $id = $r->json('task.id');
    expect(file_exists(app(\App\Services\TaskRunner::class)->dir()."/{$id}.json"))->toBeTrue()
        ->and($this->spawned)->toHaveCount(1)
        ->and($this->spawned[0])->toContain("artisan task:run '{$id}'");

    $this->postJson('/api/sites/alpha/isolate', ['php' => '8.3'], $h)->assertStatus(202)
        ->assertJsonPath('task.argv', [$bin, 'isolate', 'php@8.3', '--site=alpha']);
    $this->postJson('/api/sites/alpha/isolate', ['php' => 'eight'], $h)->assertUnprocessable();
    $this->postJson('/api/sites/alpha/unisolate', [], $h)->assertStatus(202)
        ->assertJsonPath('task.argv', [$bin, 'unisolate', '--site=alpha']);
    $this->postJson('/api/sites/nope/secure', [], $h)->assertNotFound();

    Process::assertNothingRan(); // nothing runs inline — only the spawned task would
});

it('enqueues link and unlink, and refuses to unlink a parked site', function () use ($h) {
    $target = $this->fake->parked('gamma');
    $bin = $this->fake->valetBin();

    $this->postJson('/api/sites/link', ['name' => 'gamma-link', 'path' => $target], $h)
        ->assertStatus(202)
        ->assertJsonPath('task.argv', [$bin, 'link', 'gamma-link'])
        ->assertJsonPath('task.cwd', realpath($target));
    $this->postJson('/api/sites/link', ['name' => 'bad name', 'path' => $target], $h)->assertUnprocessable();
    $this->postJson('/api/sites/link', ['name' => 'x', 'path' => '/nowhere'], $h)
        ->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'Directory not found'));

    $this->deleteJson('/api/sites/alpha/link', [], $h)->assertUnprocessable();
    $this->deleteJson('/api/sites/api/link', [], $h)->assertStatus(202)
        ->assertJsonPath('task.argv', [$bin, 'unlink', 'api']);
});

it('renders the sites and site-information commands', function () {
    Process::fake([
        // Real `about --json` mixes strings and arrays (logs is a channel list); the command must render both.
        '*artisan*about*' => Process::result(json_encode([
            'environment' => ['laravel_version' => '12.0.0', 'debug_mode' => 'ENABLED'],
            'drivers' => ['cache' => 'file', 'logs' => ['stack', 'single']],
        ])),
    ]);

    $this->artisan('sites')->expectsOutputToContain('alpha')->expectsOutputToContain('grafana')->assertSuccessful();
    $this->artisan('site-information alpha')
        ->expectsOutputToContain('12.0.0')
        ->expectsOutputToContain('logs=stack / single')
        ->assertSuccessful();
    $this->artisan('site-information nope')->assertFailed();
});
