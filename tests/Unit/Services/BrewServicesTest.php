<?php

use Illuminate\Support\Facades\Process;
use Tests\Support\FakeServicesWorld;

beforeEach(fn () => $this->w = new FakeServicesWorld);
afterEach(fn () => $this->w->destroy());

it('lists brew services agents from launchd and the agents dir, with driver and data facts', function () {
    $this->w->brewCluster('postgresql@14', 'var/postgresql@14', ['PG_VERSION' => "14\n"], 5432);
    $this->w->brewCluster('redis', 'var/db/redis', ['dump.rdb' => 'x'], 6379, loaded: false);   // plist only: starts at login
    file_put_contents($this->w->root.'/agents/homebrew.mxcl.nginx.plist', '<plist/>');            // no driver
    $this->w->brewFs->formula('postgresql@14', '14.19', ['postgres']);

    $list = collect($this->w->brewServices->list())->keyBy('formula');

    expect($list->keys()->all())->toBe(['nginx', 'postgresql@14', 'redis'])
        ->and($list['postgresql@14'])->toMatchArray(['loaded' => true, 'pid' => 864, 'type' => 'postgresql', 'has_data' => true, 'port' => 5432, 'answering' => true])
        ->and($list['redis'])->toMatchArray(['loaded' => false, 'pid' => null, 'type' => 'redis', 'has_data' => true, 'answering' => false])
        ->and($list['redis']['plist'])->toEndWith('homebrew.mxcl.redis.plist')
        ->and($list['nginx']['type'])->toBeNull()
        ->and(array_map(fn ($s) => $s['formula'], $this->w->brewServices->adoptable()))->toBe(['postgresql@14', 'redis']);
});

it('stops through brew services', function () {
    $this->w->brewCluster('redis', 'var/db/redis', [], 6379);
    $this->w->brewServices->stop('redis');

    Process::assertRan(fn ($p) => $p->command === [$this->w->brewFs->root.'/bin/brew', 'services', 'stop', 'redis']);
    expect($this->w->brewServices->find('redis'))->toBeNull();
});
