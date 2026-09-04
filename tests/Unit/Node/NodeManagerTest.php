<?php

use App\Services\Node\NodeManager;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeServicesWorld;

beforeEach(function () {
    $this->w = new FakeServicesWorld;
    // fnm lives outside the fake brew prefix: that path contains "brew", which the world's '*brew*install*' fake would match
    $this->fnm = "{$this->w->root}/fnm";
    file_put_contents($this->fnm, "#!/bin/sh\n");
    chmod($this->fnm, 0755);
    $this->versions = ['20.18.0', '22.11.0 default'];
    Process::fake([
        '*which*' => fn () => Process::result(is_file($this->fnm) ? "{$this->fnm}\n" : ''),
        "*fnm' 'ls'*" => function () {
            return Process::result(implode("\n", array_map(fn ($v) => "* v{$v}", $this->versions))."\n* system\n");
        },
        "*fnm' 'install'*" => function ($p) {
            $this->versions[] = $p->command[2] === '--lts' ? '24.1.0 lts-latest' : rtrim($p->command[2], '.').(substr_count($p->command[2], '.') === 2 ? '' : '.9.9');

            return Process::result("Installing Node v…\n");
        },
        "*fnm' 'default'*" => Process::result(''),
    ]);
    $this->node = new NodeManager($this->w->brew, $this->w->shell);
    $this->site = "{$this->w->root}/site";
    mkdir($this->site);
});

afterEach(fn () => $this->w->destroy());

it('reads fnm ls, matches partial pins, installs and sets the default', function () {
    expect($this->node->available())->toBeTrue()
        ->and($this->node->installed())->toBe(['versions' => ['22.11.0', '20.18.0'], 'default' => '22.11.0', 'lts' => null])
        ->and($this->node->satisfied('22'))->toBe('22.11.0')
        ->and($this->node->satisfied('v20.18'))->toBe('20.18.0')
        ->and($this->node->satisfied('18'))->toBeNull()
        ->and($this->node->satisfied('lts'))->toBeNull();        // nothing aliased lts-* yet

    $lines = [];
    expect($this->node->install('22', function (string $l) use (&$lines) {
        $lines[] = $l;
    }))->toBe('22.11.0')
        ->and(end($lines))->toBe('node 22.11.0 already installed');
    Process::assertNotRan(fn ($p) => ($p->command[1] ?? '') === 'install');

    expect($this->node->install('18', function (string $l) use (&$lines) {
        $lines[] = $l;
    }))->toBe('18.9.9');
    Process::assertRan(fn ($p) => $p->command === [$this->fnm, 'install', '18']);
    expect($this->node->install('lts', fn () => null))->toBe('24.1.0');
    Process::assertRan(fn ($p) => $p->command === [$this->fnm, 'install', '--lts']);
    expect($this->node->installed()['lts'])->toBe('24.1.0')->and($this->node->satisfied('lts'))->toBe('24.1.0');

    $this->node->setDefault('20');
    Process::assertRan(fn ($p) => $p->command === [$this->fnm, 'default', '20.18.0']);
    expect(fn () => $this->node->setDefault('16'))->toThrow(RuntimeException::class, 'not installed');
});

it('pins sites through .nvmrc and wraps commands with fnm exec', function () {
    expect($this->node->pinOf($this->site))->toBeNull();
    $this->node->pin($this->site, 'v22');
    expect(file_get_contents("{$this->site}/.nvmrc"))->toBe("22\n")
        ->and($this->node->pinOf($this->site))->toBe('22')
        ->and($this->node->resolve($this->site))->toBe(['pin' => '22', 'installed' => '22.11.0', 'effective' => '22.11.0', 'default' => '22.11.0']);
    file_put_contents("{$this->site}/.nvmrc", "18\n");
    expect($this->node->resolve($this->site)['installed'])->toBeNull();

    expect($this->node->execArgv('22', ['npm', 'ci']))->toBe([$this->fnm, 'exec', '--using', '22', '--', 'npm', 'ci'])
        ->and($this->node->execArgv(null, ['npm', 'ci']))->toBe([$this->fnm, 'exec', '--using', '22.11.0', '--', 'npm', 'ci']);   // no pin → the default

    unlink($this->fnm);
    expect($this->node->available())->toBeFalse()
        ->and($this->node->installed())->toBe(['versions' => [], 'default' => null, 'lts' => null])
        ->and($this->node->execArgv('22', ['npm', 'ci']))->toBe(['npm', 'ci'])
        ->and(fn () => $this->node->install('22', fn () => null))->toThrow(RuntimeException::class, 'fnm is not installed');
});
