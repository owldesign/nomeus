<?php

use App\Services\LaunchdManager;
use App\Support\Probe;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeBrew;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/devkit-svccli-'.uniqid();
    mkdir("{$this->root}/devkit", 0755, true);
    $this->brewFs = (new FakeBrew)->formula('redis', '8.2.1', ['redis-server'])->formula('postgresql@17', '17.6', ['initdb', 'postgres'])
        ->installed('8.4', '8.4.25')->linked('8.4');
    file_put_contents("{$this->root}/devkit/config.json", json_encode(['brew_prefix' => $this->brewFs->root]));
    config()->set('devkit.config_path', "{$this->root}/devkit/config.json");
    $this->valetFs = new \Tests\Support\FakeValet;
    config()->set('devkit.valet_config_dir', $this->valetFs->configDir);
    config()->set('devkit.valet_bin', $this->valetFs->valetBin());
    config()->set('devkit.launch_agents_dir', "{$this->root}/agents");
    config()->set('devkit.uid', 501);
    mkdir("{$this->root}/agents", 0755, true);

    $this->answering = [];
    $this->loaded = [];
    $this->mock(Probe::class, function ($m) {
        $m->shouldReceive('tcp')->andReturnUsing(fn (string $h, int $p) => in_array($p, $this->answering, true));
        $m->shouldReceive('unix')->andReturn(false);
    });
    $labelOf = fn (string $t): string => substr($t, strrpos($t, '/') + 1);
    Process::fake([
        '*launchctl*print-disabled*' => Process::result(''),
        '*launchctl*print*' => fn ($p) => $p->command[2] === 'gui/501'
            ? Process::result("gui/501 = {}\n")
            : (in_array($labelOf($p->command[2]), $this->loaded, true) ? Process::result("state = running\n\tpid = 7\n") : Process::result('', '', 113)),
        // placeholders: tests override these keys in place, which keeps them ahead of the catch-all.
        // The list key is exact — "*launchctl*list*" would also match "launchctl bootstrap … .plist".
        "*'launchctl' 'list'*" => Process::result(''),
        '*brew*services*stop*' => Process::result(''),
        '*cp*-a*' => Process::result(''),
        '*psql*' => Process::result(''),
        '*launchctl*bootstrap*' => function ($p) {
            $label = basename($p->command[3], '.plist');
            $name = substr($label, strlen(LaunchdManager::PREFIX));
            $this->answering[] = app(\App\Services\ServiceManager::class)->find($name)?->port ?? 0;
            $this->loaded[] = $label;

            return Process::result('');
        },
        '*launchctl*bootout*' => function ($p) use ($labelOf) {
            $this->loaded = array_values(array_diff($this->loaded, [$labelOf($p->command[2])]));
            $this->answering = [];

            return Process::result('');
        },
        '*launchctl*' => Process::result(''),
        '*--version*' => Process::result("stub 1.0\n"),   // driver binary pre-flight
        '*initdb*' => Process::result("ok\n"),
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
    $this->brewFs->destroy();
    $this->valetFs->destroy();
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
    // services:env writes its error via getErrorStyle() — stderr under ConsoleOutput, so `>> .env` can't capture it.
    // The test harness has one buffer for both streams, so only the exit code is observable here.
    $this->artisan('services:env nope')->assertFailed();
    $this->artisan('services:logs nope')->assertFailed();
});

it('lists adoptable brew services and adopts one, and runs the doctor', function () {
    // a brew redis cluster: agent plist + data dir + answering on 6379
    $dataDir = $this->brewFs->root.'/var/db/redis';
    mkdir($dataDir, 0755, true);
    file_put_contents("$dataDir/dump.rdb", 'x');
    file_put_contents("{$this->root}/agents/homebrew.mxcl.redis.plist", '<plist/>');
    $this->answering = [6379];
    Process::fake([
        "*'launchctl' 'list'*" => Process::result("854\t0\thomebrew.mxcl.redis\n"),
        '*brew*services*stop*' => function () {
            $this->answering = [];
            @unlink("{$this->root}/agents/homebrew.mxcl.redis.plist");

            return Process::result('');
        },
        '*cp*-a*' => function ($p) {
            \Illuminate\Support\Facades\File::copyDirectory(rtrim(preg_replace('#/\\.$#', '', $p->command[2]), '/'), rtrim($p->command[3], '/'));

            return Process::result('');
        },
    ]);

    $this->artisan('services:adopt')->expectsOutputToContain('var/db/redis')->assertSuccessful();
    $this->artisan('services:adopt redis')
        ->expectsOutputToContain('brew services stop redis')
        ->expectsOutputToContain('adopted from')
        ->assertSuccessful();
    $this->artisan('services:list')->expectsOutputToContain('6379')->assertSuccessful();
    expect(file_exists("{$this->root}/devkit/services/redis/data/dump.rdb"))->toBeTrue();

    $this->artisan('services:doctor')->expectsOutputToContain('launchd')->assertSuccessful();
    $this->artisan('services:doctor --json')->expectsOutputToContain('"level": "ok"')->assertSuccessful();
    $this->artisan('services:adopt nginx')->expectsOutputToContain('No devkit driver')->assertFailed();
});

it('upgrades an instance to another formula from the cli', function () {
    $this->brewFs->formula('postgresql@16', '16.10', ['initdb', 'postgres']);
    $this->artisan('services:create postgresql 17 --name=pg')->assertSuccessful();

    $this->artisan('services:upgrade pg postgresql@16 --yes')
        ->expectsOutputToContain('starting pg as postgresql@16')
        ->expectsOutputToContain('(was postgresql@17)')
        ->assertSuccessful();
    $this->artisan('services:upgrade pg redis --yes')->expectsOutputToContain('is not a PostgreSQL formula')->assertFailed();
    $this->artisan('services:upgrade nope redis --yes')->assertFailed();
});

it('creates a site-bound reverb with --site and refuses without', function () {
    $dir = $this->valetFs->parked('alpha', laravel: true);
    mkdir("$dir/vendor/laravel/reverb", 0755, true);

    $this->artisan('services:create reverb')->expectsOutputToContain('--site=<name>')->assertFailed();
    $this->artisan('services:create reverb --site=alpha')
        ->expectsOutputToContain('starting reverb-alpha on 127.0.0.1:8080')
        ->assertSuccessful();
    $this->artisan('services:env reverb-alpha')->expectsOutput('BROADCAST_CONNECTION=reverb')->expectsOutput('REVERB_PORT=8080')->assertSuccessful();
    $this->artisan('services:available')->expectsOutputToContain('site package laravel/reverb')->assertSuccessful();
});
