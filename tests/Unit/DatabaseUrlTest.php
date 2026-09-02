<?php

use App\Services\DatabaseUrl;

it('builds a mysql url with laravel defaults and masks the password for display', function () {
    $db = DatabaseUrl::fromEnv(['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'fsv', 'DB_PASSWORD' => 'p@ss'], '/s', 'fsv');

    expect($db['kind'])->toBe('url')
        ->and($db['target'])->toBe('mysql://root:p%40ss@127.0.0.1:3306/fsv?name=fsv&statusColor=ffc83d')
        ->and($db['display'])->toBe('mysql://root:•••••@127.0.0.1:3306/fsv')
        ->and($db['driver'])->toBe('mysql');
});

it('maps pgsql and mariadb, with explicit host, port and user', function () {
    $pg = DatabaseUrl::fromEnv(['DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'db.local', 'DB_PORT' => '5433', 'DB_USERNAME' => 'app', 'DB_DATABASE' => 'x'], '/s', 'x');
    $mdb = DatabaseUrl::fromEnv(['DB_CONNECTION' => 'mariadb', 'DB_DATABASE' => 'x'], '/s', 'x');

    expect($pg['target'])->toBe('postgresql://app@db.local:5433/x?name=x&statusColor=ffc83d')
        ->and($pg['display'])->toBe('postgresql://app@db.local:5433/x')
        ->and($mdb['target'])->toStartWith('mysql://root@127.0.0.1:3306/x');
});

it('resolves sqlite paths like config/database.php does', function () {
    expect(DatabaseUrl::fromEnv([], '/s', 'x'))->toMatchArray(['kind' => 'file', 'target' => '/s/database/database.sqlite'])
        ->and(DatabaseUrl::fromEnv(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => 'db/app.sqlite'], '/s', 'x')['target'])->toBe('/s/db/app.sqlite')
        ->and(DatabaseUrl::fromEnv(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => '/abs/app.sqlite'], '/s', 'x')['target'])->toBe('/abs/app.sqlite');
});

it('passes DB_URL through, masked for display', function () {
    $db = DatabaseUrl::fromEnv(['DB_URL' => 'postgresql://u:secret@h:5432/d'], '/s', 'x');

    expect($db['target'])->toBe('postgresql://u:secret@h:5432/d')
        ->and($db['display'])->toBe('postgresql://u:•••••@h:5432/d')
        ->and($db['driver'])->toBe('postgresql');
});

it('refuses drivers without a gui url', function () {
    DatabaseUrl::fromEnv(['DB_CONNECTION' => 'sqlsrv'], '/s', 'x');
})->throws(RuntimeException::class, 'DB_CONNECTION=sqlsrv');
