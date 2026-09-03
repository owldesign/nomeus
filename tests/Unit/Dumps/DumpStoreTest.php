<?php

use App\Services\Dumps\DumpStore;
use App\Support\NomeusConfig;

beforeEach(function () {
    $this->file = sys_get_temp_dir().'/nomeus-store-'.uniqid().'.sqlite';
    $this->store = new DumpStore(new NomeusConfig('/nonexistent/config.json'), $this->file);
    $this->row = fn (string $kind, string $req, string $text = 'x') => ['kind' => $kind, 'request_key' => $req, 'uri' => '/u', 'method' => 'GET', 'command' => null, 'file' => '/f.php', 'line' => 1, 'text' => $text, 'html' => null, 'payload' => null];
});

afterEach(function () {
    foreach (glob("{$this->file}*") ?: [] as $f) {
        @unlink($f);
    }
});

it('stores, pages by kind/request/after-id, counts, lists requests, clears and prunes', function () {
    foreach ([['dump', 'r1', 'a'], ['query', 'r1', 'select 1'], ['dump', 'r2', 'b'], ['log', 'r2', 'c']] as [$k, $r, $t]) {
        $this->store->insert(($this->row)($k, $r, $t));
    }

    expect(array_column($this->store->page(), 'text'))->toBe(['a', 'select 1', 'b', 'c'])
        ->and(array_column($this->store->page('dump'), 'text'))->toBe(['a', 'b'])
        ->and(array_column($this->store->page(null, 'r2'), 'text'))->toBe(['b', 'c'])
        ->and(array_column($this->store->page(null, null, 2), 'text'))->toBe(['b', 'c'])
        ->and(array_column($this->store->page(null, null, null, 1), 'text'))->toBe(['c'])
        ->and($this->store->counts())->toBe(['dump' => 2, 'log' => 1, 'query' => 1])
        ->and($this->store->counts('r1'))->toBe(['dump' => 1, 'query' => 1])
        ->and($this->store->latestRequestKey())->toBe('r2')
        ->and(array_column($this->store->requests(), 'request_key'))->toBe(['r2', 'r1'])
        ->and($this->store->requests()[0]['n'])->toBe(2);

    $this->store->prune(keep: 1);
    expect(array_column($this->store->page(), 'text'))->toBe(['c']);
    expect($this->store->clear())->toBe(1)->and($this->store->page())->toBe([]);
});
