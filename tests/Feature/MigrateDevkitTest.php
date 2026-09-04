<?php

use App\Services\LaunchdManager;
use App\Services\ServiceManager;
use App\Support\Probe;
use App\Support\TaskSpawner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeBrew;
use Tests\Support\FakeValet;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nomeus-migrate-'.uniqid();
    mkdir("{$this->root}/agents", 0755, true);
    $this->brewFs = (new FakeBrew)->formula('redis', '8.2.1', ['redis-server'])->installed('8.4', '8.4.25')->linked('8.4');
    $this->valetFs = new FakeValet;
    // ~/.nomeus with only a fresh config.json (what a first `nomeus` run leaves behind). This is also what
    // keeps BrewBridge on the fake prefix: with no config at all it would fall back to the real /opt/homebrew.
    mkdir("{$this->root}/nomeus", 0755, true);
    file_put_contents("{$this->root}/nomeus/config.json", json_encode(['brew_prefix' => $this->brewFs->root]));
    config()->set('nomeus.config_path', "{$this->root}/nomeus/config.json");
    config()->set('nomeus.launch_agents_dir', "{$this->root}/agents");
    config()->set('nomeus.uid', 501);
    config()->set('nomeus.valet_config_dir', $this->valetFs->configDir);
    config()->set('nomeus.valet_bin', $this->valetFs->valetBin());

    // the old world: ~/.devkit with a redis instance, its old-label plist loaded, old ini, old shim, dashboard linked as devkit
    $this->old = "{$this->root}/devkit";
    mkdir("{$this->old}/services/redis/data", 0755, true);
    foreach (['conf', 'run', 'logs'] as $d) {
        mkdir("{$this->old}/services/redis/{$d}", 0755, true);
    }
    file_put_contents("{$this->old}/config.json", json_encode(['brew_prefix' => $this->brewFs->root, 'ide' => 'vscode']));
    file_put_contents("{$this->old}/services/redis/service.json", json_encode([
        'name' => 'redis', 'type' => 'redis', 'formula' => 'redis', 'version' => '8.2.1', 'port' => 6379,
        'dir' => "{$this->old}/services/redis", 'created_at' => '2026-09-01T00:00:00+00:00', 'options' => [],
    ]));
    file_put_contents("{$this->old}/services/redis/data/dump.rdb", 'DATA');
    file_put_contents("{$this->root}/agents/dev.zhuk.devkit.svc.redis.plist", '<plist/>');
    file_put_contents($this->brewFs->root.'/etc/php/8.4/conf.d/99-devkit.ini', "auto_prepend_file={$this->old}/php/prepend.php\n");
    file_put_contents($this->brewFs->root.'/etc/php/8.4/conf.d/20-xdebug.ini.devkit-off', "zend_extension=x\n");
    symlink('/nowhere/bin/devkit', $this->brewFs->root.'/bin/devkit');
    $this->valetFs->linked('devkit', base_path());
    $this->valetFs->secured('devkit');
    touch("{$this->valetFs->configDir}/valet.sock");

    $this->answering = [];
    $this->mock(Probe::class, function ($m) {
        $m->shouldReceive('tcp')->andReturnUsing(fn ($h, $p) => in_array($p, $this->answering, true));
        $m->shouldReceive('unix')->andReturn(true);
    });
    $this->mock(TaskSpawner::class, fn ($m) => $m->shouldReceive('spawn'));
    Process::fake([
        '*launchctl*print-disabled*' => Process::result(''),
        '*launchctl*print*' => fn ($p) => match (true) {
            $p->command[2] === 'gui/501' => Process::result("gui/501 = {}\n"),                    // the domain (doctor)
            str_contains($p->command[2], 'dev.zhuk.devkit') => Process::result("state = running\n"), // old agent is running
            default => Process::result('', '', 113),                                              // new label: not loaded yet
        },
        "*'launchctl' 'list'*" => Process::result(''),
        '*launchctl*bootstrap*' => function ($p) {
            $name = substr(basename($p->command[3], '.plist'), strlen(LaunchdManager::PREFIX));
            $this->answering[] = app(ServiceManager::class)->find($name)?->port ?? 0;

            return Process::result('');
        },
        '*launchctl*' => Process::result(''),
        '*--version*' => Process::result("stub 1.0\n"),
        '*bin/valet*' => Process::result("ok\n"),
        '*pgrep*' => Process::result('', '', 1),
        '*which*' => Process::result(''),
        '*git*' => Process::result(''),
        '*brew*outdated*' => Process::result(json_encode(['formulae' => []])),
        '*php*-r*' => Process::result('8.4.25'),
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
    $this->brewFs->destroy();
    $this->valetFs->destroy();
});

it('moves everything that carried the old name and restarts what was running', function () {
    $this->artisan("migrate:devkit --from={$this->old} --dry-run")
        ->expectsOutputToContain('stop 1 devkit agent(s): redis')
        ->expectsOutputToContain("move {$this->old} → {$this->root}/nomeus")
        ->assertSuccessful();
    expect(is_dir($this->old))->toBeTrue();   // dry-run changed nothing

    $this->artisan("migrate:devkit --from={$this->old} --yes")
        ->expectsOutputToContain('stopped dev.zhuk.devkit.svc.redis')
        ->expectsOutputToContain('1 agent(s) rewritten')
        ->expectsOutputToContain('redis: running on 6379')
        ->expectsOutputToContain('valet restart php')
        ->expectsOutputToContain('migrated')
        ->assertSuccessful();

    $new = "{$this->root}/nomeus";
    expect(is_dir($this->old))->toBeFalse()
        ->and(file_get_contents("$new/services/redis/data/dump.rdb"))->toBe('DATA')
        ->and(json_decode(file_get_contents("$new/services/redis/service.json"), true)['dir'])->toBe("$new/services/redis")
        ->and(json_decode(file_get_contents("$new/config.json"), true)['ide'])->toBe('vscode')         // the user's config survived …
        ->and(is_file("$new/config.json.fresh"))->toBeTrue()                                             // … the fresh one kept for reference
        ->and(is_file("{$this->root}/agents/dev.zhuk.devkit.svc.redis.plist"))->toBeFalse()
        ->and(file_get_contents("{$this->root}/agents/dev.nomeus.svc.redis.plist"))->toContain("$new/services/redis")
        ->and(is_file($this->brewFs->root.'/etc/php/8.4/conf.d/99-devkit.ini'))->toBeFalse()
        ->and(file_get_contents($this->brewFs->root.'/etc/php/8.4/conf.d/99-nomeus.ini'))->toContain("auto_prepend_file=$new/php/prepend.php")
        ->and(is_file($this->brewFs->root.'/etc/php/8.4/conf.d/20-xdebug.ini.nomeus-off'))->toBeTrue()
        ->and(is_link($this->brewFs->root.'/bin/devkit'))->toBeFalse()
        ->and(readlink($this->brewFs->root.'/bin/nomeus'))->toBe(base_path('bin/nomeus'));

    $valet = $this->valetFs->valetBin();
    Process::assertRan(fn ($p) => $p->command === ['launchctl', 'bootout', 'gui/501/dev.zhuk.devkit.svc.redis']);
    Process::assertRan(fn ($p) => $p->command === ['launchctl', 'bootstrap', 'gui/501', "{$this->root}/agents/dev.nomeus.svc.redis.plist"]);
    Process::assertRan(fn ($p) => $p->command === [$valet, 'unsecure', 'devkit']);
    Process::assertRan(fn ($p) => $p->command === [$valet, 'unlink', 'devkit']);
    Process::assertRan(fn ($p) => $p->command === [$valet, 'link', 'nomeus']);
    Process::assertRan(fn ($p) => $p->command === [$valet, 'secure', 'nomeus']);
    Process::assertRan(fn ($p) => $p->command === [$valet, 'restart', 'php']);

    // second run: nothing left to migrate — and it points at --resume
    $this->artisan("migrate:devkit --from={$this->old} --yes")->expectsOutputToContain('--resume finishes')->assertSuccessful();

    // --resume: redo ini + agents (starting all) + dashboard + shim on the moved dir
    unlink("{$this->root}/agents/dev.nomeus.svc.redis.plist");
    $this->answering = [];
    $this->artisan("migrate:devkit --from={$this->old} --resume --yes")
        ->expectsOutputToContain('resume: steps 1–2 already done')
        ->expectsOutputToContain('1 agent(s) rewritten')
        ->expectsOutputToContain('redis: running on 6379')
        ->assertSuccessful();
    expect(is_file("{$this->root}/agents/dev.nomeus.svc.redis.plist"))->toBeTrue();
});

it('fixes the php ini before starting anything, and a failing instance does not stop the others', function () {
    // a second instance whose bootstrap never makes the port answer
    mkdir("{$this->old}/services/redis-2/data", 0755, true);
    foreach (['conf', 'run', 'logs'] as $d) {
        mkdir("{$this->old}/services/redis-2/{$d}", 0755, true);
    }
    file_put_contents("{$this->old}/services/redis-2/service.json", json_encode([
        'name' => 'redis-2', 'type' => 'redis', 'formula' => 'redis', 'version' => '8.2.1', 'port' => 6380,
        'dir' => "{$this->old}/services/redis-2", 'created_at' => '2026-09-01T00:00:00+00:00', 'options' => [],
    ]));
    file_put_contents("{$this->root}/agents/dev.zhuk.devkit.svc.redis-2.plist", '<plist/>');
    $order = [];
    Process::fake([
        '*launchctl*bootstrap*' => function ($p) use (&$order) {
            $name = substr(basename($p->command[3], '.plist'), strlen(LaunchdManager::PREFIX));
            $order[] = "bootstrap {$name}";
            if ($name !== 'redis-2') {
                $this->answering[] = app(ServiceManager::class)->find($name)?->port ?? 0;
            }

            return Process::result('');
        },
        '*bin/valet*' => function ($p) use (&$order) {          // same key as the beforeEach fake, so it replaces it in place
            if (($p->command[1] ?? '') === 'restart') {
                $order[] = 'valet restart php';
            }

            return Process::result("ok\n");
        },
    ]);
    app()->bind(ServiceManager::class, function ($app) {
        $m = $app->build(ServiceManager::class);
        $m->readyTimeout = 1;

        return $m;
    });

    $this->artisan("migrate:devkit --from={$this->old} --yes")
        ->expectsOutputToContain('redis: running on 6379')
        ->expectsOutputToContain('redis-2: did not start')
        ->expectsOutputToContain('did not start: redis-2')
        ->assertFailed();

    expect($order[0])->toBe('valet restart php');                                                   // ini + fpm before any start
    expect(is_file("{$this->root}/agents/dev.nomeus.svc.redis-2.plist"))->toBeTrue();               // its agent exists regardless
});

it('refuses when the new dir already has content', function () {
    mkdir("{$this->root}/nomeus/services", 0755, true);
    $this->artisan("migrate:devkit --from={$this->old} --yes")->expectsOutputToContain('already has content')->assertFailed();
    expect(is_dir($this->old))->toBeTrue();
});
