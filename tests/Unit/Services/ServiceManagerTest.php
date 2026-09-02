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

use Tests\Support\FakeServicesWorld;

beforeEach(function () {
    $this->w = new FakeServicesWorld;
    $this->m = $this->w->manager;
    $this->launchd = $this->w->launchd;
    $this->brewFs = $this->w->brewFs;
});

afterEach(fn () => $this->w->destroy());

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
    $this->w->answering = [6379];                                   // something else (brew services?) owns 6379
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

it('strips the source\'s lock files from a clone and from a cold start', function () {
    $src = $this->m->create('postgresql');
    chmod($src->dataDir(), 0700);                                          // what initdb leaves behind
    file_put_contents($src->dataDir().'/postmaster.pid', "31337\n");   // as if the source were mid-shutdown when copied
    file_put_contents($src->dataDir().'/base.dat', 'DATA');
    // the fake bootout doesn't "finish shutting down", so the file stays on the source; clone must still strip the copy
    $this->m->shutdownTimeout = 0;
    $clone = $this->m->clone($src, 'pg-copy');

    clearstatcache();
    expect(file_exists($clone->dataDir().'/postmaster.pid'))->toBeFalse()
        ->and(file_get_contents($clone->dataDir().'/base.dat'))->toBe('DATA')
        ->and(fileperms($clone->dataDir()) & 0777)->toBe(0700);            // postgres would refuse 0755

    // cold start of an instance launchd isn't holding: stale lock removed before bootstrap
    $this->m->stop($clone);
    file_put_contents($clone->dataDir().'/postmaster.pid', "1\n");
    $this->m->start($clone);
    expect(file_exists($clone->dataDir().'/postmaster.pid'))->toBeFalse();
});

it('reports a crash loop distinctly from starting', function () {
    $i = $this->m->create('redis', start: false);
    Process::fake(['*launchctl*print*' => Process::result("state = waiting\n\tlast exit code = 1\n")]); // loaded, no pid, died

    expect($this->m->status($i))->toMatchArray(['running' => false, 'loaded' => true, 'pid' => null, 'last_exit' => 1, 'crashing' => true]);
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

it('retargets an instance to another formula of the same type and restarts it', function () {
    $this->brewFs->formula('postgresql@16', '16.10', ['initdb', 'postgres']);
    $i = $this->m->create('postgresql');           // postgresql@17
    file_put_contents($i->dataDir().'/base.dat', 'DATA');

    $u = $this->m->retarget($i, 'postgresql@16');

    expect($u->formula)->toBe('postgresql@16')
        ->and($u->version)->toBe('16.10')
        ->and($u->options['previous_formula'])->toBe('postgresql@17')
        ->and(file_get_contents($u->dataDir().'/base.dat'))->toBe('DATA')
        ->and(file_get_contents($this->launchd->plistPath('postgresql')))->toContain('/opt/postgresql@16/bin/postgres')
        ->and($this->m->status($u)['running'])->toBeTrue()
        ->and(json_decode(file_get_contents($u->file()), true)['formula'])->toBe('postgresql@16');
    Process::assertRan(fn ($p) => $p->command === ['launchctl', 'bootout', 'gui/501/dev.zhuk.devkit.svc.postgresql']);

    expect(fn () => $this->m->retarget($u, 'redis'))->toThrow(RuntimeException::class, 'is not a PostgreSQL formula')
        ->and(fn () => $this->m->retarget($u, 'postgresql@16'))->toThrow(RuntimeException::class, 'already runs');
});

it('allocates aux listeners per instance so two typesenses never share a peering port', function () {
    $a = $this->m->create('typesense', start: false);
    $b = $this->m->create('typesense', start: false);

    expect([$a->port, $a->options['peering_port']])->toBe([8108, 8107])
        ->and([$b->port, $b->options['peering_port']])->toBe([8109, 8110])
        ->and(strlen($a->options['api_key']))->toBe(40)
        ->and($a->options['api_key'])->not->toBe($b->options['api_key'])
        ->and(file_get_contents($this->launchd->plistPath('typesense-2')))->toContain('--peering-port=8110');

    $c = $this->m->clone($a, 'typesense-copy');
    expect($c->options['api_key'])->toBe($a->options['api_key'])        // secrets carry over …
        ->and($c->options['peering_port'])->not->toBe(8107);           // … listeners don't
});

it('creates a site-bound reverb from the site\'s php and vendor dir', function () {
    $sitePath = $this->w->reverbSite('alpha');
    $this->w->valetFs->parked('beta', laravel: true);
    $this->w->valetFs->isolated('beta', '8.3');
    $this->w->reverbSite('beta');

    $i = $this->m->create('reverb', site: 'alpha');
    expect($i->name)->toBe('reverb-alpha')
        ->and($i->formula)->toBe('laravel/reverb')
        ->and($i->version)->toBe('1.5.0')
        ->and($i->port)->toBe(8080)
        ->and($i->options)->toMatchArray(['site' => 'alpha', 'site_path' => $sitePath, 'php_bin_dir' => $this->brewFs->root.'/bin'])
        ->and($i->options['app_key'])->toHaveLength(20)
        ->and($this->m->status($i))->toMatchArray(['running' => true, 'installed' => true]);
    $plist = file_get_contents($this->launchd->plistPath('reverb-alpha'));
    expect($plist)->toContain("<key>WorkingDirectory</key>\n    <string>{$sitePath}</string>")
        ->and($plist)->toContain('<string>reverb:start</string>');
    Process::assertNotRan(fn ($p) => ($p->command[1] ?? '') === 'install');   // no brew involved

    $j = $this->m->create('reverb', site: 'beta');                                   // isolated site → its own php
    expect($j->options['php_bin_dir'])->toBe($this->brewFs->root.'/opt/php@8.3/bin')->and($j->port)->toBe(8081);

    expect(fn () => $this->m->create('reverb'))->toThrow(RuntimeException::class, '--site=<name>')
        ->and(fn () => $this->m->create('reverb', site: 'nope'))->toThrow(RuntimeException::class, 'not parked or linked')
        ->and(fn () => $this->m->clone($i, 'reverb-copy'))->toThrow(RuntimeException::class, 'create another with --site');

    $this->w->valetFs->parked('gamma', laravel: true);
    expect(fn () => $this->m->create('reverb', site: 'gamma'))->toThrow(RuntimeException::class, 'composer require laravel/reverb');

    $this->w->reverbSite('reverb-test');                                                   // site already named after the type
    expect($this->m->create('reverb', site: 'reverb-test', start: false)->name)->toBe('reverb-test');
    $this->w->reverbSite('shop.example.com');
    expect($this->m->create('reverb', site: 'shop.example.com', start: false)->name)->toBe('reverb-shop-example-com');
});

it('trusts the tap before installing a tapped formula that is missing', function () {
    $this->brewFs->uninstall('typesense/tap/typesense-server');
    Process::fake([
        '*brew*trust*' => Process::result("Trusted typesense/tap\n"),
        '*brew*install*' => function () {
            $this->brewFs->formula('typesense/tap/typesense-server', '30.2', ['typesense-server']);

            return Process::result("==> Pouring typesense-server\n");
        },
    ]);
    $lines = [];

    $i = $this->m->create('typesense', start: false, log: function (string $l) use (&$lines) { $lines[] = $l; });

    expect($i->formula)->toBe('typesense/tap/typesense-server')
        ->and($lines[0])->toBe('brew trust typesense/tap')
        ->and($lines[1])->toStartWith('brew install typesense/tap/typesense-server');
    $bin = $this->brewFs->root.'/bin/brew';
    Process::assertRan(fn ($p) => $p->command === [$bin, 'trust', 'typesense/tap']);
    Process::assertRan(fn ($p) => $p->command === [$bin, 'install', 'typesense/tap/typesense-server']);
});
