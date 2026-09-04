<?php

use App\Services\LogSources;
use App\Services\ValetBridge;
use App\Support\NomeusConfig;
use App\Support\Shell;
use Tests\Support\FakeValet;

beforeEach(function () {
    $this->valetFs = new FakeValet;
    $this->site = realpath($this->valetFs->parked('smoke', laravel: true));
    mkdir("{$this->site}/storage/logs", 0755, true);
    file_put_contents("{$this->site}/storage/logs/laravel-2026-09-01.log", "old\n");
    file_put_contents("{$this->site}/storage/logs/laravel.log", "new\n");
    touch("{$this->site}/storage/logs/laravel-2026-09-01.log", time() - 86400);
    file_put_contents("{$this->site}/storage/logs/.gitignore", '*');
    mkdir("{$this->valetFs->configDir}/Log", 0755, true);
    file_put_contents("{$this->valetFs->configDir}/Log/nginx-error.log", "2026/09/02 01:00:00 [error] boom\n");
    $this->valetFs->parked('static');   // no storage/logs → no sources
    $this->valetFs->proxied('api', 'http://127.0.0.1:9000');
    $config = new NomeusConfig(sys_get_temp_dir().'/nomeus-nonexistent-config.json');
    $this->sources = new LogSources(new ValetBridge(new Shell($config), $this->valetFs->configDir));
});

afterEach(fn () => $this->valetFs->destroy());

it('lists site logs newest first plus valet logs, and resolves only those paths', function () {
    $all = $this->sources->all();

    expect(array_map(fn ($s) => "{$s['group']}/{$s['label']}", $all))->toBe(['smoke/laravel.log', 'smoke/laravel-2026-09-01.log', 'valet/nginx-error.log'])
        ->and($all[0])->toMatchArray(['kind' => 'laravel', 'size' => 4])
        ->and($all[2]['kind'])->toBe('nginx')
        ->and($this->sources->latestFor('smoke')['label'])->toBe('laravel.log')
        ->and($this->sources->latestFor('static'))->toBeNull()
        ->and($this->sources->resolve("{$this->site}/storage/logs/laravel.log")['id'])->toBe($all[0]['id'])
        ->and($this->sources->resolve("{$this->site}/storage/logs/../logs/laravel.log")['label'])->toBe('laravel.log')
        ->and($this->sources->resolve("{$this->site}/.env"))->toBeNull()
        ->and($this->sources->resolve('/etc/hosts'))->toBeNull()
        ->and($this->sources->resolve("{$this->site}/storage/logs/.gitignore"))->toBeNull();
});
