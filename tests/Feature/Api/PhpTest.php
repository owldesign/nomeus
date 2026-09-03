<?php

use App\Support\Probe;
use App\Support\TaskSpawner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeBrew;
use Tests\Support\FakeValet;

beforeEach(function () {
    Cache::flush();
    $this->valetFs = new FakeValet;
    $this->brewFs = (new FakeBrew)
        ->installed('8.3', '8.3.26')->installed('8.4', '8.4.25')->linked('8.4')
        ->available(['8.1', '8.3', '8.4', '8.5']);
    file_put_contents($this->valetFs->root.'/nomeus.json', json_encode(['brew_prefix' => $this->brewFs->root]));
    config()->set('nomeus.config_path', $this->valetFs->root.'/nomeus.json');
    config()->set('nomeus.valet_config_dir', $this->valetFs->configDir);
    config()->set('nomeus.valet_bin', $this->valetFs->valetBin());
    config()->set('nomeus.platform_check', $this->valetFs->root.'/missing.php'); // tests assume floor 8.2
    $this->valetFs->parked('alpha');

    $this->mock(Probe::class, function ($m) {
        $m->shouldReceive('tcp')->andReturn(false);
        $m->shouldReceive('unix')->andReturn(false);
    });
    $this->spawned = [];
    $this->mock(TaskSpawner::class, fn ($m) => $m->shouldReceive('spawn')
        ->andReturnUsing(function (string $cmd) { $this->spawned[] = $cmd; }));
    Process::fake([
        '*brew*outdated*' => Process::result(json_encode(['formulae' => [['name' => 'php@8.4', 'current_version' => '8.4.26']]])),
        '*pgrep*' => Process::result('', '', 1),
        '*php*-r*' => Process::result('8.4.25'),
    ]);
});

afterEach(function () {
    $this->valetFs->destroy();
    $this->brewFs->destroy();
});

$h = ['X-Nomeus' => '1'];

it('lists php state', function () {
    $this->getJson('/api/php')
        ->assertOk()
        ->assertJsonPath('data.global', '8.4')
        ->assertJsonPath('data.installed.0.version', '8.3')
        ->assertJsonPath('data.installed.1.version', '8.4')
        ->assertJsonPath('data.installed.1.linked', true)
        ->assertJsonPath('data.installed.1.sites', ['alpha'])
        ->assertJsonPath('data.installed.1.outdated', '8.4.26')
        ->assertJsonPath('data.installable', ['8.1', '8.5'])
        ->assertJsonPath('data.min_php', '8.2');
});

it('enqueues use, install and update as tasks with guards', function () use ($h) {
    $valet = $this->valetFs->valetBin();
    $brew = $this->brewFs->root.'/bin/brew';

    $this->postJson('/api/php/8.3/use', [], $h)->assertStatus(202)
        ->assertJsonPath('task.argv', [$valet, 'use', 'php@8.3'])->assertJsonPath('task.label', 'valet use php@8.3');
    $this->postJson('/api/php/8.1/install', [], $h)->assertStatus(202)
        ->assertJsonPath('task.argv', [$brew, 'install', 'shivammathur/php/php@8.1']);
    $this->postJson('/api/php/8.4/update', [], $h)->assertStatus(202)
        ->assertJsonPath('task.argv', [$brew, 'upgrade', 'php@8.4']);

    $this->postJson('/api/php/8.1/use', [], $h)->assertUnprocessable();       // not installed
    $this->postJson('/api/php/8.4/install', [], $h)->assertUnprocessable();   // already installed
    $this->postJson('/api/php/7.4/install', [], $h)->assertUnprocessable();   // not offered
    $this->postJson('/api/php/eight/use', [], $h)->assertNotFound();          // route constraint

    expect($this->spawned)->toHaveCount(3);
    Process::assertNotRan(fn ($p) => is_array($p->command) && in_array('use', $p->command, true));
});

it('refuses unsafe requests without the nomeus header', function () {
    $this->postJson('/api/php/8.3/use')->assertForbidden();
    expect($this->spawned)->toBe([]);
});

it('renders php:list and refuses a bad php:install inline', function () {
    $this->artisan('php:list')
        ->expectsOutputToContain('8.4.25')
        ->expectsOutputToContain('installable: 8.1, 8.5')
        ->assertSuccessful();
    $this->artisan('php:list --json')->expectsOutputToContain('"installable"')->assertSuccessful();
    $this->artisan('php:install 8.4')->expectsOutputToContain('already installed')->assertFailed();
    $this->artisan('php:update 8.1')->expectsOutputToContain('not installed')->assertFailed();
});

it('runs php:install inline through brew when the version is offered', function () {
    Process::fake(['*brew*install*' => Process::result("==> Installing php@8.1\n")]);

    $this->artisan('php:install 8.1')->expectsOutputToContain('php@8.1 installed')->assertSuccessful();
    Process::assertRan(fn ($p) => $p->command === [$this->brewFs->root.'/bin/brew', 'install', 'shivammathur/php/php@8.1']);
});
