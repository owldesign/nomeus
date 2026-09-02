<?php

use App\Services\LaunchdManager;
use App\Support\Probe;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeBrew;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/devkit-svccli-'.uniqid();
    mkdir("{$this->root}/devkit", 0755, true);
    $this->brewFs = (new FakeBrew)->formula('redis', '8.2.1', ['redis-server'])->formula('postgresql@17', '17.6', ['initdb', 'postgres']);
    file_put_contents("{$this->root}/devkit/config.json", json_encode(['brew_prefix' => $this->brewFs->root]));
    config()->set('devkit.config_path', "{$this->root}/devkit/config.json");
    config()->set('devkit.launch_agents_dir', "{$this->root}/agents");
    config()->set('devkit.uid', 501);

    $this->answering = [];
    $this->loaded = [];
    $this->mock(Probe::class, function ($m) {
        $m->shouldReceive('tcp')->andReturnUsing(fn (string $h, int $p) => in_array($p, $this->answering, true));
        $m->shouldReceive('unix')->andReturn(false);
    });
    $labelOf = fn (string $t): string => substr($t, strrpos($t, '/') + 1);
    Process::fake([
        '*launchctl*print-disabled*' => Process::result(''),
        '*launchctl*print*' => fn ($p) => in_array($labelOf($p->command[2]), $this->loaded, true)
            ? Process::result("state = running\n\tpid = 7\n") : Process::result('', '', 113),
        '*launchctl*bootstrap*' => function ($p) {
            preg_match('/<string>(?:-p|--port)<\/string>\s*<string>(\d+)<\/string>/', file_get_contents($p->command[3]), $m);
            $this->answering[] = (int) ($m[1] ?? 0);
            $this->loaded[] = basename($p->command[3], '.plist');

            return Process::result('');
        },
        '*launchctl*bootout*' => function ($p) use ($labelOf) {
            $this->loaded = array_values(array_diff($this->loaded, [$labelOf($p->command[2])]));
            $this->answering = [];

            return Process::result('');
        },
        '*launchctl*' => Process::result(''),
        '*initdb*' => Process::result("ok\n"),
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
    $this->brewFs->destroy();
});

it('lists what is available and per-type versions', function () {
    $this->artisan('services:available')->expectsOutputToContain('postgresql')->expectsOutputToContain('6379')->assertSuccessful();
    // one substring per table row (Mockery gives each write to the first matching expectation)
    $this->artisan('services:versions postgresql')->expectsOutputToContain('17.6')->expectsOutputToContain('postgresql@16')->assertSuccessful();
    $this->artisan('services:versions mongo')->expectsOutputToContain('Unknown service type')->assertFailed();
});

it('creates, lists, prints env, stops, starts and deletes an instance', function () {
    $this->artisan('services:create redis')
        ->expectsOutputToContain('starting redis on 127.0.0.1:6379')
        ->expectsOutputToContain('redis: redis 8.2.1 on 127.0.0.1:6379')
        ->assertSuccessful();
    expect(file_exists("{$this->root}/devkit/services/redis/service.json"))->toBeTrue()
        ->and(file_exists("{$this->root}/agents/dev.zhuk.devkit.svc.redis.plist"))->toBeTrue();

    $this->artisan('services:list')->expectsOutputToContain('running')->assertSuccessful();
    $this->artisan('services:list --json')->expectsOutputToContain('"formula": "redis"')->assertSuccessful();
    $this->artisan('services:env redis')->expectsOutput('REDIS_HOST=127.0.0.1')->expectsOutput('REDIS_PORT=6379')->assertSuccessful();

    $this->artisan('services:stop redis')->expectsOutputToContain('redis stopped')->assertSuccessful();
    Process::assertRan(fn ($p) => $p->command === ['launchctl', 'disable', 'gui/501/dev.zhuk.devkit.svc.redis']);
    $this->artisan('services:list')->expectsOutputToContain('stopped')->assertSuccessful();

    $this->artisan('services:start redis')->expectsOutputToContain('redis running')->assertSuccessful();
    $this->artisan('services:restart redis')->expectsOutputToContain('redis restarted')->assertSuccessful();

    $this->artisan('services:delete redis --force')->expectsOutputToContain('redis deleted')->assertSuccessful();
    expect(is_dir("{$this->root}/devkit/services/redis"))->toBeFalse()
        ->and(file_exists("{$this->root}/agents/dev.zhuk.devkit.svc.redis.plist"))->toBeFalse();
});

it('creates a named postgres on a chosen port without starting it, and clones it', function () {
    $this->artisan('services:create postgresql 17 --name=fsv-pg --port=5433 --no-start')
        ->expectsOutputToContain('initdb')
        ->expectsOutputToContain('(not started)')
        ->assertSuccessful();
    Process::assertNotRan(fn ($p) => is_array($p->command) && ($p->command[1] ?? '') === 'bootstrap');

    $this->artisan('services:clone fsv-pg fsv-pg-copy')
        ->expectsOutputToContain('fsv-pg-copy: postgresql@17 on 127.0.0.1:5432')   // standard port was free
        ->assertSuccessful();
    $this->artisan('services:env fsv-pg-copy')->expectsOutput('DB_PORT=5432')->assertSuccessful();
});

it('reports the usual mistakes', function () {
    $this->artisan('services:create mongo')->expectsOutputToContain('Unknown service type')->assertFailed();
    $this->artisan('services:create postgresql 9')->expectsOutputToContain('No PostgreSQL formula for version [9]')->assertFailed();
    $this->artisan('services:start nope')->expectsOutputToContain('No service [nope]')->assertFailed();
    $this->artisan('services:env nope')->assertFailed();
    $this->artisan('services:logs nope')->assertFailed();
});
