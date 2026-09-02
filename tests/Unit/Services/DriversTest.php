<?php

use App\Services\Services\DriverRegistry;
use App\Services\Services\MailpitDriver;
use App\Services\Services\MariaDbDriver;
use App\Services\Services\MeilisearchDriver;
use App\Services\Services\ReverbDriver;
use App\Services\Services\SeaweedFsDriver;
use App\Services\Services\SiteBound;
use App\Services\Services\TypesenseDriver;
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
        ->and((new MySqlDriver)->formulaFor(null))->toBe('mysql@9.7')
        ->and((new MySqlDriver)->formulaFor('8.4'))->toBe('mysql@8.4')
        ->and((new MySqlDriver)->formulaFor('mysql'))->toBe('mysql')
        ->and((new RedisDriver)->formulaFor(null))->toBe('redis')
        ->and([$pg->binary(), (new MySqlDriver)->binary(), (new MariaDbDriver)->binary(), (new RedisDriver)->binary()])->toBe(['postgres', 'mysqld', 'mariadbd', 'redis-server']);
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

it('registers the drivers, rejects unknown types, and maps formulae back to drivers', function () {
    $r = new DriverRegistry;
    expect(array_keys($r->all()))->toBe(['postgresql', 'mysql', 'mariadb', 'redis', 'meilisearch', 'typesense', 'seaweedfs', 'reverb', 'mailpit'])
        ->and(fn () => $r->get('mongo'))->toThrow(RuntimeException::class, 'Unknown service type')
        ->and($r->driverForFormula('postgresql@14')?->type())->toBe('postgresql')
        ->and($r->driverForFormula('mysql')?->type())->toBe('mysql')
        ->and($r->driverForFormula('mariadb@11.4')?->type())->toBe('mariadb')
        ->and($r->driverForFormula('redis')?->type())->toBe('redis')
        ->and($r->driverForFormula('nginx'))->toBeNull();
});

it('describes mariadb', function () use ($instance) {
    $i = $instance('mariadb', 'mariadb', 3307);
    $d = new MariaDbDriver;

    expect($d->initialize($i, '/b')[0]['argv'])->toBe(['/b/mariadb-install-db', '--datadir=/svc/x/data', '--auth-root-authentication-method=normal', '--skip-test-db'])
        ->and($d->programArguments($i, '/b')[0])->toBe('/b/mariadbd')
        ->and($d->programArguments($i, '/b'))->toContain('--port=3307', '--bind-address=127.0.0.1')
        ->and($d->env($i)['DB_CONNECTION'])->toBe('mariadb')
        ->and($d->formulaFor('11.4'))->toBe('mariadb@11.4');
});

it('knows where brew keeps each formula\'s data and how to finish an adoption', function () use ($instance) {
    expect((new PostgresDriver)->brewDataDir('/opt/homebrew', 'postgresql@14'))->toBe('/opt/homebrew/var/postgresql@14')
        ->and((new MySqlDriver)->brewDataDir('/opt/homebrew', 'mysql'))->toBe('/opt/homebrew/var/mysql')
        ->and((new MariaDbDriver)->brewDataDir('/opt/homebrew', 'mariadb'))->toBe('/opt/homebrew/var/mysql')
        ->and((new RedisDriver)->brewDataDir('/opt/homebrew', 'redis'))->toBe('/opt/homebrew/var/db/redis')
        ->and((new PostgresDriver)->lockFilesIn('/d'))->toBe(['/d/postmaster.pid']);

    $pg = (new PostgresDriver)->postAdopt($instance('postgresql', 'postgresql@14', 5432), '/b');
    expect($pg)->toHaveCount(1)
        ->and($pg[0]['argv'][0])->toBe('/b/psql')
        ->and($pg[0]['argv'])->toContain('-p', '5432', '-d', 'postgres')
        ->and(end($pg[0]['argv']))->toContain("CREATE ROLE postgres SUPERUSER LOGIN")
        ->and((new RedisDriver)->postAdopt($instance('redis', 'redis', 1), '/b'))->toBe([]);
});

it('names the lock and identity files a clone must not inherit', function () use ($instance) {
    expect((new PostgresDriver)->staleFiles($instance('postgresql', 'postgresql@17', 1)))->toBe(['/svc/x/data/postmaster.pid', '/svc/x/data/postmaster.opts'])
        ->and((new MySqlDriver)->staleFiles($instance('mysql', 'mysql@8.4', 1)))->toContain('/svc/x/run/mysql.pid', '/svc/x/data/auto.cnf')
        ->and((new RedisDriver)->staleFiles($instance('redis', 'redis', 1)))->toBe([]);
});

it('describes meilisearch in development mode', function () use ($instance) {
    $i = $instance('meilisearch', 'meilisearch', 7701);
    $d = new MeilisearchDriver;

    expect($d->programArguments($i, '/b'))->toBe(['/b/meilisearch', '--http-addr', '127.0.0.1:7701', '--db-path', '/svc/x/data/data.ms', '--dump-dir', '/svc/x/data/dumps', '--env', 'development', '--no-analytics'])
        ->and($d->env($i))->toBe(['SCOUT_DRIVER' => 'meilisearch', 'MEILISEARCH_HOST' => 'http://127.0.0.1:7701', 'MEILISEARCH_KEY' => ''])
        ->and($d->auxPorts())->toBe([]);
});

it('describes typesense with a generated key and a peering port', function () {
    $d = new TypesenseDriver;
    $opts = $d->defaultOptions();
    $i = new ServiceInstance(name: 'x', type: 'typesense', formula: 'typesense/tap/typesense-server', version: '29', port: 8108, dir: '/svc/x', createdAt: 'now',
        options: $opts + ['peering_port' => 8107]);

    expect(strlen($opts['api_key']))->toBe(40)
        ->and($d->auxPorts())->toBe(['peering' => 8107])
        ->and($d->programArguments($i, '/b'))->toContain('--api-key='.$opts['api_key'], '--api-port=8108', '--peering-port=8107', '--data-dir=/svc/x/data')
        ->and($d->env($i))->toMatchArray(['SCOUT_DRIVER' => 'typesense', 'TYPESENSE_PORT' => '8108', 'TYPESENSE_API_KEY' => $opts['api_key']])
        ->and($d->formulae())->toBe(['typesense/tap/typesense-server']);
});

it('describes seaweedfs with the s3 port as the main port and three aux listeners', function () {
    $d = new SeaweedFsDriver;
    $i = new ServiceInstance(name: 'x', type: 'seaweedfs', formula: 'seaweedfs', version: '3', port: 8333, dir: '/svc/x', createdAt: 'now',
        options: ['master_port' => 9333, 'volume_port' => 8080, 'filer_port' => 8888]);

    expect($d->versionArgs())->toBe(['version'])
        ->and($d->auxPorts())->toBe(['master' => 9333, 'volume' => 8080, 'filer' => 8888])
        ->and($d->programArguments($i, '/b'))->toContain('/b/weed', 'server', '-dir=/svc/x/data', '-master.port=9333', '-volume.port=8080', '-filer.port=8888', '-s3', '-s3.port=8333')
        ->and($d->env($i))->toMatchArray(['FILESYSTEM_DISK' => 's3', 'AWS_ENDPOINT' => 'http://127.0.0.1:8333', 'AWS_USE_PATH_STYLE_ENDPOINT' => 'true', 'AWS_ACCESS_KEY_ID' => 'devkit']);
});

it('describes reverb as site-bound: runs the site\'s php from the site dir', function () {
    $d = new ReverbDriver;
    $opts = $d->defaultOptions();
    $i = new ServiceInstance(name: 'reverb-alpha', type: 'reverb', formula: 'laravel/reverb', version: '1.5.0', port: 8080, dir: '/svc/x', createdAt: 'now',
        options: $opts + ['site' => 'alpha', 'site_path' => '/Users/me/Sites/alpha', 'php_bin_dir' => '/b']);

    expect($d)->toBeInstanceOf(SiteBound::class)
        ->and($d->siteRequirement())->toBe('vendor/laravel/reverb')
        ->and(array_keys($opts))->toBe(['app_id', 'app_key', 'app_secret'])
        ->and($d->workingDirectory($i))->toBe('/Users/me/Sites/alpha')
        ->and($d->programArguments($i, '/b'))->toBe(['/b/php', 'artisan', 'reverb:start', '--host=127.0.0.1', '--port=8080', '--no-interaction'])
        ->and($d->env($i))->toMatchArray(['BROADCAST_CONNECTION' => 'reverb', 'REVERB_APP_KEY' => $opts['app_key'], 'REVERB_PORT' => '8080', 'VITE_REVERB_APP_KEY' => '"${REVERB_APP_KEY}"']);
});

it('describes mailpit: smtp main port, http aux port, laravel mail env', function () {
    $d = new MailpitDriver;
    $i = new ServiceInstance(name: 'mailpit', type: 'mailpit', formula: 'mailpit', version: '1.31.0', port: 1025, dir: '/svc/m', createdAt: 'now', options: ['http_port' => 8025]);

    expect($d->auxPorts())->toBe(['http' => 8025])
        ->and($d->versionArgs())->toBe(['version'])
        ->and($d->programArguments($i, '/b'))->toBe(['/b/mailpit', '--smtp', '127.0.0.1:1025', '--listen', '127.0.0.1:8025', '--database', '/svc/m/data/mailpit.db'])
        ->and($d->env($i))->toMatchArray(['MAIL_MAILER' => 'smtp', 'MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => '1025', 'MAIL_USERNAME' => 'null', 'MAIL_ENCRYPTION' => 'null']);
});
