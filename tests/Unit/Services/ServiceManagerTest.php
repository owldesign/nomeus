<?php

use App\Services\BrewBridge;
use App\Services\LaunchdManager;
use App\Services\ServiceManager;
use App\Services\Services\DriverRegistry;
use App\Support\DevkitConfig;
use App\Support\Probe;
use App\Support\Shell;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeBrew;

/**
 * A tiny world: fake brew prefix with postgres/redis stubs, temp ~/.devkit and LaunchAgents,
 * a Probe whose "answering" ports the fake launchctl toggles on bootstrap/bootout.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/devkit-svc-'.uniqid();
    mkdir("{$this->root}/devkit", 0755, true);
    $this->brewFs = (new FakeBrew)
        ->formula('postgresql@17', '17.6', ['initdb', 'postgres'])
        ->formula('redis', '8.2.1', ['redis-server']);
    file_put_contents("{$this->root}/devkit/config.json", json_encode(['brew_prefix' => $this->brewFs->root]));

    $config = new DevkitConfig("{$this->root}/devkit/config.json");
    $shell = new Shell($config);
    $this->answering = [];                       // ports that currently answer
    $this->probe = Mockery::mock(Probe::class);
    $this->probe->shouldReceive('tcp')->andReturnUsing(fn (string $h, int $p) => in_array($p, $this->answering, true));
    $this->launchd = new LaunchdManager($shell, "{$this->root}/agents", 501);
    $this->m = new ServiceManager($config, new BrewBridge($shell), new DriverRegistry, $this->launchd, $shell, $this->probe);

    // launchctl: bootstrap makes the plist's port answer; bootout silences it. Port is read from the plist.
    $this->loaded = [];
    $labelOf = fn (string $target): string => substr($target, strrpos($target, '/') + 1);
    $portOf = function (string $plist): int {
        preg_match('/<string>(?:-p|--port)<\/string>\s*<string>(\d+)<\/string>|<string>--port=(\d+)<\/string>/', file_get_contents($plist), $m);

        return (int) (($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? 0));
    };
    Process::fake([
        '*launchctl*print-disabled*' => Process::result(''),
        '*launchctl*print*' => fn ($p) => in_array($labelOf($p->command[2]), $this->loaded, true)
            ? Process::result("state = running\n\tpid = 99\n")
            : Process::result('', '', 113),
        '*launchctl*bootstrap*' => function ($p) use ($portOf) {
            $this->answering[] = $portOf($p->command[3]);
            $this->loaded[] = basename($p->command[3], '.plist');

            return Process::result('');
        },
        '*launchctl*bootout*' => function ($p) use ($labelOf) {
            $label = $labelOf($p->command[2]);
            $this->loaded = array_values(array_diff($this->loaded, [$label]));
            if ($i = $this->m->find(substr($label, strlen(LaunchdManager::PREFIX)))) {
                $this->answering = array_values(array_diff($this->answering, [$i->port]));
            }

            return Process::result('');
        },
        '*launchctl*' => Process::result(''),
        '*initdb*' => Process::result("Success. You can now start the database server\n"),
        '*brew*install*' => Process::result("==> Installing\n"),
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
    $this->brewFs->destroy();
});

it('creates a postgres instance: dirs, initdb, plist, launchd, ready', function () {
    $lines = [];
    $i = $this->m->create('postgresql', null, null, null, true, function (string $l) use (&$lines) { $lines[] = $l; });

    expect($i->name)->toBe('postgresql')
        ->and($i->formula)->toBe('postgresql@17')
        ->and($i->version)->toBe('17.6')
        ->and($i->port)->toBe(5432)
        ->and(is_dir($i->dataDir()))->toBeTrue()
        ->and(json_decode(file_get_contents($i->file()), true)['port'])->toBe(5432)
        ->and(file_get_contents($this->launchd->plistPath('postgresql')))->toContain('<string>'.$this->brewFs->root.'/opt/postgresql@17/bin/postgres</string>')
        ->and($this->m->status($i))->toMatchArray(['running' => true, 'loaded' => true, 'pid' => 99, 'installed' => true])
        ->and($lines)->toContain('initdb', 'starting postgresql on 127.0.0.1:5432');

    Process::assertRan(fn ($p) => $p->command[0] === $this->brewFs->root.'/opt/postgresql@17/bin/initdb' && in_array('--auth=trust', $p->command, true));
    Process::assertRan(fn ($p) => $p->command === ['launchctl', 'enable', 'gui/501/dev.zhuk.devkit.svc.postgresql']);
    Process::assertRan(fn ($p) => $p->command[1] === 'bootstrap');
});

it('takes the standard port when free, the next free one otherwise, and names instances type, type-2', function () {
    $this->answering = [6379];                                   // something else (brew services?) owns 6379
    $a = $this->m->create('redis', start: false);
    $b = $this->m->create('redis', start: false);

    expect($a->name)->toBe('redis')->and($a->port)->toBe(6380)
        ->and($b->name)->toBe('redis-2')->and($b->port)->toBe(6381)
        ->and(fn () => $this->m->create('redis', port: 6380, start: false))->toThrow(RuntimeException::class, 'claimed by another devkit service')
        ->and(fn () => $this->m->create('redis', port: 6379, start: false))->toThrow(RuntimeException::class, 'already in use on this machine')
        ->and(fn () => $this->m->create('redis', name: 'redis', start: false))->toThrow(RuntimeException::class, 'already exists')
        ->and(fn () => $this->m->create('redis', name: 'Bad Name', start: false))->toThrow(RuntimeException::class, 'lowercase');
});

it('installs the formula first when it is missing, and rolls back a failed init', function () {
    $this->brewFs->formula('mysql@8.4', '8.4.6', ['mysqld']);          // present …
    File::deleteDirectory($this->brewFs->root.'/opt');                    // … but pretend nothing is installed
    mkdir($this->brewFs->root.'/opt');
    Process::fake(['*brew*install*' => function () {
        $this->brewFs->formula('mysql@8.4', '8.4.6', ['mysqld']);      // brew "installs" it
        return Process::result("==> Pouring mysql@8.4\n");
    }, '*mysqld*--initialize*' => Process::result('', 'InnoDB: cannot allocate', 1)]);

    expect(fn () => $this->m->create('mysql', '8.4', start: false))->toThrow(RuntimeException::class, 'mysqld --initialize-insecure failed');
    Process::assertRan(fn ($p) => $p->command === [$this->brewFs->root.'/bin/brew', 'install', 'mysql@8.4']);
    expect($this->m->find('mysql'))->toBeNull()                             // rolled back
        ->and(file_exists($this->launchd->plistPath('mysql')))->toBeFalse();
});

it('stops, starts and restarts through launchd and waits for the port', function () {
    $i = $this->m->create('redis');
    expect($this->m->status($i)['running'])->toBeTrue();

    $this->m->stop($i);
    Process::assertRan(fn ($p) => $p->command === ['launchctl', 'bootout', 'gui/501/dev.zhuk.devkit.svc.redis']);
    Process::assertRan(fn ($p) => $p->command === ['launchctl', 'disable', 'gui/501/dev.zhuk.devkit.svc.redis']);
    expect($this->m->status($i)['running'])->toBeFalse();

    $this->m->start($i);
    expect($this->m->status($i)['running'])->toBeTrue();

    $this->m->restart($i);
    Process::assertRan(fn ($p) => $p->command === ['launchctl', 'kickstart', '-k', 'gui/501/dev.zhuk.devkit.svc.redis']);
});

it('writes a full environment into the agent so postgres has a locale', function () {
    $this->m->create('postgresql', start: false);
    $xml = file_get_contents($this->launchd->plistPath('postgresql'));

    expect($xml)->toContain("<key>LC_ALL</key>\n        <string>en_US.UTF-8</string>")
        ->and($xml)->toContain('<key>LANG</key>')
        ->and($xml)->toContain('<key>HOME</key>')
        ->and($xml)->toContain('<key>PATH</key>');
});

it('keeps a failed-to-start instance but stops it, with a hint', function () {
    $this->m->readyTimeout = 1;
    Process::fake(['*launchctl*bootstrap*' => Process::result('')]); // loads, but the port never answers

    expect(fn () => $this->m->create('redis'))->toThrow(RuntimeException::class, 'Kept redis stopped for inspection');
    Process::assertRan(fn ($p) => $p->command === ['launchctl', 'bootout', 'gui/501/dev.zhuk.devkit.svc.redis']);
    Process::assertRan(fn ($p) => $p->command === ['launchctl', 'disable', 'gui/501/dev.zhuk.devkit.svc.redis']);
    expect($this->m->find('redis'))->not->toBeNull();
});

it('clones with data, a new port and a new agent, restoring the source', function () {
    $src = $this->m->create('redis');
    file_put_contents($src->dataDir().'/dump.rdb', 'REDIS');

    $clone = $this->m->clone($src, 'redis-copy');

    expect($clone->port)->toBe(6380)
        ->and($clone->formula)->toBe('redis')
        ->and(file_get_contents($clone->dataDir().'/dump.rdb'))->toBe('REDIS')
        ->and(file_exists($this->launchd->plistPath('redis-copy')))->toBeTrue()
        ->and($this->m->status($src)['running'])->toBeTrue()      // stopped for the copy, started again
        ->and($this->m->status($clone)['running'])->toBeTrue()
        ->and(array_map(fn ($i) => $i->name, $this->m->all()))->toBe(['redis', 'redis-copy']);
});

it('deletes, optionally keeping data, and prints env', function () {
    $this->brewFs->formula('postgresql@16', '16.10', ['initdb', 'postgres']);
    $i = $this->m->create('postgresql', '16', 'pg16', 5440, start: false);
    expect($this->m->env($i))->toBe(['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5440', 'DB_USERNAME' => 'postgres', 'DB_PASSWORD' => '']);

    $this->m->delete($i, keepData: true);
    expect($this->m->find('pg16'))->toBeNull()
        ->and(is_dir($i->dataDir()))->toBeTrue()
        ->and(file_exists($this->launchd->plistPath('pg16')))->toBeFalse();

    File::deleteDirectory($i->dir);
    $j = $this->m->create('redis', start: false);
    $this->m->delete($j);
    expect(is_dir($j->dir))->toBeFalse();
});
