<?php

use Illuminate\Support\Facades\Process;
use Tests\Support\FakeServicesWorld;

beforeEach(function () {
    $this->w = new FakeServicesWorld;
    $this->m = $this->w->manager;
    $this->w->brewFs->formula('postgresql@14', '14.19', ['initdb', 'postgres', 'psql']);
    $this->src = $this->w->brewCluster('postgresql@14', 'var/postgresql@14', [
        'PG_VERSION' => "14\n",
        'postmaster.pid' => "864\n",        // brew's server is running: lock present until it stops
        'base.dat' => 'CLUSTER',
    ], 5432);
});

afterEach(fn () => $this->w->destroy());

it('adopts a brew postgres cluster: stop brew, copy data, standard port, agent, role', function () {
    $lines = [];
    $i = $this->m->adopt('postgresql@14', log: function (string $l) use (&$lines) {
        $lines[] = $l;
    });

    expect($i->name)->toBe('postgresql')
        ->and($i->formula)->toBe('postgresql@14')
        ->and($i->version)->toBe('14.19')
        ->and($i->port)->toBe(5432)                                                   // free once brew's copy stopped
        ->and($i->options['adopted_from'])->toBe($this->src)
        ->and(file_get_contents($i->dataDir().'/base.dat'))->toBe('CLUSTER')
        ->and(file_exists($i->dataDir().'/postmaster.pid'))->toBeFalse()             // stripped from the copy
        ->and(fileperms($i->dataDir()) & 0777)->toBe(0700)
        ->and(file_get_contents($this->src.'/base.dat'))->toBe('CLUSTER')             // brew's data untouched
        ->and($this->m->status($i)['running'])->toBeTrue()
        ->and($lines[0])->toBe('brew services stop postgresql@14')
        ->and($lines)->toContain('ensure role postgres');

    Process::assertRan(fn ($p) => $p->command[0] === $this->w->brewFs->root.'/bin/brew' && $p->command[2] === 'stop');
    Process::assertRan(fn ($p) => str_ends_with($p->command[0], '/psql') && in_array('-p', $p->command, true));
    Process::assertNotRan(fn ($p) => str_ends_with((string) ($p->command[0] ?? ''), '/initdb'));
});

it('refuses formulae it cannot drive and missing data, and installs the run formula when brew no longer has it', function () {
    expect(fn () => $this->m->adopt('nginx'))->toThrow(RuntimeException::class, 'No nomeus driver for [nginx]')
        ->and(fn () => $this->m->adopt('mysql'))->toThrow(RuntimeException::class, 'no data directory')   // data is what matters, not brew's keg
        ->and(fn () => $this->m->adopt('redis'))->toThrow(RuntimeException::class, 'no data directory');

    // brew's mysql keg is gone (upgraded away) but its data dir remains: adopt installs the formula it will run
    $this->w->brewCluster('mysql', 'var/mysql', ['ibdata1' => 'x'], 3306, loaded: false);
    expect(fn () => $this->m->adopt('mysql', runAs: 'mysql@9.7'))->toThrow(RuntimeException::class, 'has no bin dir');   // the fake install creates nothing
    Process::assertRan(fn ($p) => $p->command === [$this->w->brewFs->root.'/bin/brew', 'install', 'mysql@9.7']);
    Process::assertNotRan(fn ($p) => ($p->command[2] ?? '') === 'stop');                                                // and brew's service was never touched
});

it('keeps a name clash and a taken explicit port as errors before touching brew', function () {
    $this->m->create('redis', name: 'postgresql', start: false);   // occupies the default name

    expect(fn () => $this->m->adopt('postgresql@14', 'postgresql'))->toThrow(RuntimeException::class, 'already exists');
    Process::assertNotRan(fn ($p) => ($p->command[2] ?? '') === 'stop');

    $i = $this->m->adopt('postgresql@14', 'pg14');
    expect($i->name)->toBe('pg14')->and($i->port)->toBe(5432);
});

it('refuses to adopt when the formula binary does not load, before stopping brew', function () {
    Process::fake(['*--version*' => Process::result('', "dyld[1]: Library not loaded: /opt/homebrew/opt/protobuf/lib/libprotobuf-lite.34.0.0.dylib\n  Referenced from: mysqld\n", 134)]);

    expect(fn () => $this->m->adopt('postgresql@14'))->toThrow(RuntimeException::class, 'brew reinstall postgresql@14');
    Process::assertNotRan(fn ($p) => ($p->command[2] ?? '') === 'stop');
    expect($this->m->find('postgresql'))->toBeNull();
});

it('adopts brew data under a different formula of the same type', function () {
    $this->w->brewFs->formula('postgresql@17', '17.6', ['initdb', 'postgres', 'psql']);   // already there in the world; explicit for clarity

    $i = $this->m->adopt('postgresql@14', runAs: 'postgresql@17');

    expect($i->formula)->toBe('postgresql@17')
        ->and($i->version)->toBe('17.6')
        ->and($i->options['adopted_from'])->toBe($this->src)
        ->and($i->options['adopted_formula'])->toBe('postgresql@14')
        ->and(file_get_contents($this->w->launchd->plistPath('postgresql')))->toContain('/opt/postgresql@17/bin/postgres');

    expect(fn () => $this->m->adopt('postgresql@14', 'x', runAs: 'redis'))->toThrow(RuntimeException::class, 'is not a PostgreSQL formula');
});
