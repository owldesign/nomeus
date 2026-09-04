<?php

use App\Support\Manifest;

it('reads every key with sensible defaults', function () {
    $m = Manifest::fromArray([
        'name' => 'Smoke App', 'domain' => 'Smoke', 'php' => 8.4, 'node' => 22, 'secure' => true,
        'services' => [['type' => 'postgresql', 'version' => 17, 'instance' => 'pg17', 'database' => 'smoke'], 'redis', ['type' => 'seaweedfs', 'bucket' => 'smoke']],
        'mail' => true, 'env' => ['QUEUE_CONNECTION' => 'redis', 'EMPTY' => null], 'post-init' => ['composer install', 'php artisan migrate'],
    ], '/Users/me/Sites/smoke');

    expect($m->name)->toBe('Smoke App')
        ->and($m->domain)->toBe('smoke')
        ->and($m->php)->toBe('8.4')
        ->and($m->node)->toBe('22')
        ->and($m->secure)->toBeTrue()
        ->and($m->services)->toBe([
            ['type' => 'postgresql', 'version' => '17', 'instance' => 'pg17', 'database' => 'smoke', 'bucket' => null],
            ['type' => 'redis', 'version' => null, 'instance' => null, 'database' => null, 'bucket' => null],
            ['type' => 'seaweedfs', 'version' => null, 'instance' => null, 'database' => null, 'bucket' => 'smoke'],
        ])
        ->and($m->mail)->toBeTrue()
        ->and($m->client)->toBeTrue()               // implied by mail
        ->and($m->env)->toBe(['QUEUE_CONNECTION' => 'redis', 'EMPTY' => ''])
        ->and($m->postInit)->toBe(['composer install', 'php artisan migrate']);

    $bare = Manifest::fromArray([], '/Users/me/Sites/my-shop');
    expect($bare->name)->toBe('my-shop')->and($bare->domain)->toBe('my-shop')->and($bare->php)->toBeNull()
        ->and($bare->secure)->toBeFalse()->and($bare->mail)->toBeFalse()->and($bare->client)->toBeFalse()->and($bare->services)->toBe([]);
});

it('rejects shapes that would fail later', function () {
    expect(fn () => Manifest::fromArray(['domain' => 'bad name'], '/s'))->toThrow(RuntimeException::class, 'not a valid site name')
        ->and(fn () => Manifest::fromArray(['php' => '8'], '/s'))->toThrow(RuntimeException::class, '`php` must look like 8.4')
        ->and(fn () => Manifest::fromArray(['services' => [['version' => '17']]], '/s'))->toThrow(RuntimeException::class, 'services[0] needs a `type`')
        ->and(fn () => Manifest::fromArray(['env' => ['lower' => 'x']], '/s'))->toThrow(RuntimeException::class, 'UPPER_SNAKE');
});

it('loads nomeus.yml from a directory and explains a missing one', function () {
    $dir = sys_get_temp_dir().'/nomeus-manifest-'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/nomeus.yml", "domain: smoke\nservices:\n  - type: redis\nmail: true\n");

    expect(Manifest::exists($dir))->toBeTrue()
        ->and(Manifest::load($dir)->services[0]['type'])->toBe('redis')
        ->and(fn () => Manifest::load("$dir/nope"))->toThrow(RuntimeException::class, 'No nomeus.yml');

    // the pre-rename name still works, and loses to nomeus.yml when both exist
    rename("$dir/nomeus.yml", "$dir/dev.yml");
    expect(Manifest::exists($dir))->toBeTrue()->and(Manifest::find($dir))->toEndWith('/dev.yml');
    file_put_contents("$dir/nomeus.yml", "domain: newer\n");
    expect(Manifest::load($dir)->domain)->toBe('newer');
    unlink("$dir/dev.yml");

    file_put_contents("$dir/nomeus.yml", "domain: [unterminated\n");
    expect(fn () => Manifest::load($dir))->toThrow(RuntimeException::class, 'nomeus.yml:');
    unlink("$dir/nomeus.yml");
    rmdir($dir);
});
