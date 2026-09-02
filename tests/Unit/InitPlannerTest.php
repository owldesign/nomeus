<?php

use App\Services\Init\InitPlanner;
use App\Services\Init\InitRunner;
use App\Services\Services\DriverRegistry;
use App\Support\Manifest;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeServicesWorld;

beforeEach(function () {
    $this->w = new FakeServicesWorld;
    $this->planner = new InitPlanner($this->w->valet, $this->w->manager, new DriverRegistry, $this->w->brew, $this->w->shell);
    $this->runner = new InitRunner($this->planner);
    $this->site = realpath($this->w->valetFs->parked('smoke', laravel: true));
    file_put_contents("{$this->site}/.env.example", "APP_NAME=Laravel\nAPP_URL=http://localhost\nMAIL_MAILER=log\n");
    $this->manifest = fn (array $extra = []) => Manifest::fromArray($extra + [
        'domain' => 'smoke', 'name' => 'Smoke', 'php' => '8.3', 'node' => '22', 'secure' => true,
        'services' => [['type' => 'postgresql', 'instance' => 'pg17', 'database' => 'smoke'], ['type' => 'redis']],
        'mail' => true, 'env' => ['QUEUE_CONNECTION' => 'redis'], 'post-init' => ['php artisan migrate'],
    ], $this->site);
});

afterEach(fn () => $this->w->destroy());

$ids = fn (array $steps) => array_map(fn ($s) => $s->id.($s->skip ? ':skip' : ''), $steps);

it('plans everything on a fresh machine', function () use ($ids) {
    $steps = $this->planner->plan(($this->manifest)(['domain' => 'fresh']));   // not parked → link

    expect($ids($steps))->toBe([
        'site', 'secure', 'php', 'node', 'service:postgresql', 'service:redis', 'mail', 'client', 'env', 'post-init:0',
    ]);
    expect(collect($steps)->firstWhere('id', 'site')->detail)->toBe("valet link fresh in {$this->site}");
});

it('skips what is already in place', function () use ($ids) {
    $this->w->valetFs->secured('smoke');
    $this->w->valetFs->isolated('smoke', '8.3');
    file_put_contents("{$this->site}/.nvmrc", "22\n");
    mkdir("{$this->site}/vendor/zhuk/devkit-client", 0755, true);
    $this->w->manager->create('postgresql', null, 'pg17');
    $this->w->manager->create('redis');
    $this->w->manager->create('mailpit');

    $steps = $this->planner->plan(($this->manifest)());

    expect($ids($steps))->toBe([
        'site:skip', 'secure:skip', 'php:skip', 'node:skip', 'service:postgresql:skip', 'db:pg17', 'service:redis:skip', 'mail:skip', 'client:skip', 'env', 'post-init:0',
    ]);
});

it('runs the plan: valet, services, database, env, scripts', function () {
    $this->w->manager->create('redis');                     // already there → skipped; postgres and mailpit get created
    $lines = [];

    $result = $this->runner->run(($this->manifest)(), function (string $id, string $line) use (&$lines) { $lines[] = "$id $line"; });

    expect($result['ran'])->toBe(['secure', 'php', 'node', 'service:postgresql', 'mail', 'client', 'env', 'post-init:0'])
        ->and($result['skipped'])->toBe(['site', 'service:redis'])
        ->and(file_get_contents("{$this->site}/.nvmrc"))->toBe("22\n")
        ->and($this->w->manager->find('pg17')?->type)->toBe('postgresql')
        ->and($this->w->manager->find('mailpit'))->not->toBeNull();

    $env = file_get_contents("{$this->site}/.env");
    expect($env)->toContain('APP_NAME=Smoke')
        ->and($env)->toContain('APP_URL=https://smoke.test')
        ->and($env)->toContain('MAIL_MAILER=smtp')                   // replaced in place, not appended
        ->and(substr_count($env, 'MAIL_MAILER='))->toBe(1)
        ->and($env)->toContain('MAIL_PORT=1025')
        ->and($env)->toContain('MAIL_FROM_ADDRESS=hello@smoke.test')
        ->and($env)->toContain('DEVKIT_MAIL_TAG=smoke')
        ->and($env)->toContain('DB_CONNECTION=pgsql')
        ->and($env)->toContain('DB_DATABASE=smoke')
        ->and($env)->toContain('REDIS_PORT=6379')
        ->and($env)->toContain('QUEUE_CONNECTION=redis');

    $valet = $this->w->valetFs->valetBin();
    Process::assertRan(fn ($p) => $p->command === [$valet, 'secure', 'smoke']);
    Process::assertRan(fn ($p) => $p->command === [$valet, 'isolate', 'php@8.3', '--site=smoke']);
    Process::assertRan(fn ($p) => str_ends_with($p->command[0], '/createdb') && end($p->command) === 'smoke');
    Process::assertRan(fn ($p) => $p->command === ['composer', 'require', '--dev', 'zhuk/devkit-client:@dev', '--no-interaction'] && $p->path === $this->site);
    Process::assertRan(fn ($p) => $p->command === ['sh', '-c', 'php artisan migrate'] && $p->path === $this->site);
    expect(implode("\n", $lines))->toContain('createdb smoke: ok');
});

it('stops at the first failing step and names it', function () {
    $steps = $this->planner->plan(($this->manifest)(['php' => '7.4']));   // not installed

    expect(fn () => $this->runner->run(($this->manifest)(['php' => '7.4']), fn () => null))
        ->toThrow(RuntimeException::class, '[php] php 7.4: php@7.4 is not installed: devkit php:install 7.4');
});

it('refuses an instance name that belongs to another type', function () {
    $this->w->manager->create('redis', null, 'pg17');

    expect(fn () => $this->planner->plan(($this->manifest)()))->toThrow(RuntimeException::class, 'Instance [pg17] is a redis');
});
