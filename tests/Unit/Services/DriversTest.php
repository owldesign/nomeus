<?php

use App\Services\Services\DriverRegistry;
use App\Services\Services\MySqlDriver;
use App\Services\Services\PostgresDriver;
use App\Services\Services\RedisDriver;
use App\Support\ServiceInstance;

$instance = fn (string $type, string $formula, int $port) => new ServiceInstance(
    name: 'x', type: $type, formula: $formula, version: '1', port: $port, dir: '/svc/x', createdAt: 'now',
);

it('resolves versions to formulae', function () {
    $pg = new PostgresDriver;
    expect($pg->formulaFor(null))->toBe('postgresql@17')
        ->and($pg->formulaFor('16'))->toBe('postgresql@16')
        ->and($pg->formulaFor('postgresql@15'))->toBe('postgresql@15')
        ->and($pg->formulaFor('9'))->toBeNull()
        ->and((new MySqlDriver)->formulaFor('8.4'))->toBe('mysql@8.4')
        ->and((new MySqlDriver)->formulaFor('mysql'))->toBe('mysql')
        ->and((new RedisDriver)->formulaFor(null))->toBe('redis');
});

it('describes postgres init, argv and env', function () use ($instance) {
    $i = $instance('postgresql', 'postgresql@17', 5433);
    $d = new PostgresDriver;

    expect($d->initialize($i, '/b')[0]['argv'])->toBe(['/b/initdb', '-D', '/svc/x/data', '-U', 'postgres', '--auth=trust', '--encoding=UTF8', '--no-locale'])
        ->and($d->programArguments($i, '/b'))->toBe(['/b/postgres', '-D', '/svc/x/data', '-p', '5433', '-k', '/svc/x/run', '-c', 'listen_addresses=127.0.0.1'])
        ->and($d->env($i))->toBe(['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5433', 'DB_USERNAME' => 'postgres', 'DB_PASSWORD' => '']);
});

it('describes mysql init, argv and env, with mysqlx off', function () use ($instance) {
    $i = $instance('mysql', 'mysql@8.4', 3307);
    $d = new MySqlDriver;

    expect($d->initialize($i, '/b')[0]['argv'])->toBe(['/b/mysqld', '--initialize-insecure', '--datadir=/svc/x/data', '--log-error=/svc/x/logs/mysql-error.log'])
        ->and($d->programArguments($i, '/b'))->toContain('--port=3307', '--socket=/svc/x/run/mysql.sock', '--bind-address=127.0.0.1', '--mysqlx=OFF')
        ->and($d->env($i)['DB_CONNECTION'])->toBe('mysql')
        ->and($d->env($i)['DB_USERNAME'])->toBe('root');
});

it('describes redis with no init step', function () use ($instance) {
    $i = $instance('redis', 'redis', 6380);
    $d = new RedisDriver;

    expect($d->initialize($i, '/b'))->toBe([])
        ->and($d->programArguments($i, '/b'))->toBe(['/b/redis-server', '--port', '6380', '--dir', '/svc/x/data', '--bind', '127.0.0.1', '--daemonize', 'no', '--save', '60', '1'])
        ->and($d->env($i))->toBe(['REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => '6380', 'REDIS_PASSWORD' => 'null']);
});

it('registers the three drivers and rejects unknown types', function () {
    $r = new DriverRegistry;
    expect(array_keys($r->all()))->toBe(['postgresql', 'mysql', 'redis'])
        ->and(fn () => $r->get('mongo'))->toThrow(RuntimeException::class, 'Unknown service type');
});

it('names the lock and identity files a clone must not inherit', function () use ($instance) {
    expect((new PostgresDriver)->staleFiles($instance('postgresql', 'postgresql@17', 1)))->toBe(['/svc/x/data/postmaster.pid', '/svc/x/data/postmaster.opts'])
        ->and((new MySqlDriver)->staleFiles($instance('mysql', 'mysql@8.4', 1)))->toContain('/svc/x/run/mysql.pid', '/svc/x/data/auto.cnf')
        ->and((new RedisDriver)->staleFiles($instance('redis', 'redis', 1)))->toBe([]);
});
