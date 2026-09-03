<?php

use App\Support\NomeusConfig;
use App\Support\Editor;
use App\Support\Shell;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->cfg = sys_get_temp_dir().'/nomeus-editor-'.uniqid().'.json';
    $this->editor = fn (string $ide) => new Editor(new Shell($c = new NomeusConfig($this->cfg)), tap($c, fn ($c) => $c->set('ide', $ide)));
});

afterEach(fn () => @unlink($this->cfg));

it('builds url-scheme commands for phpstorm, vscode and cursor', function () {
    $e = ($this->editor)('phpstorm');
    expect($e->fileCommand('/x/a b.php', 12))->toBe(['open', 'phpstorm://open?file=%2Fx%2Fa%20b.php&line=12'])
        ->and($e->fileCommand('/x/php.ini'))->toBe(['open', 'phpstorm://open?file=%2Fx%2Fphp.ini'])
        ->and($e->dirCommand('/x/proj'))->toBe(['open', '-a', 'PhpStorm', '/x/proj']);

    $e = ($this->editor)('vscode');
    expect($e->fileCommand('/x/php.ini', 3))->toBe(['open', 'vscode://file/x/php.ini:3'])
        ->and($e->dirCommand('/x/proj'))->toBe(['open', 'vscode://file/x/proj']);

    expect(($this->editor)('cursor')->fileCommand('/x/f'))->toBe(['open', 'cursor://file/x/f']);
});

it('uses the cli launcher when present, otherwise open -a', function () {
    Process::fake(['*which*subl*' => Process::result("/opt/homebrew/bin/subl\n"), '*which*zed*' => Process::result('', '', 1)]);

    expect(($this->editor)('sublime')->fileCommand('/x/f', 9))->toBe(['subl', '/x/f:9'])
        ->and(($this->editor)('zed')->fileCommand('/x/f', 9))->toBe(['open', '-a', 'Zed', '/x/f'])
        ->and(($this->editor)('zed')->dirCommand('/x/d'))->toBe(['open', '-a', 'Zed', '/x/d']);
});

it('falls back to the macos default app', function () {
    $e = ($this->editor)('open');
    expect($e->fileCommand('/x/f'))->toBe(['open', '-t', '/x/f'])
        ->and($e->dirCommand('/x/d'))->toBe(['open', '/x/d']);
});

it('reports a failed open with the ide name', function () {
    Process::fake(['*' => Process::result('', 'Unable to find application named PhpStorm', 1)]);

    ($this->editor)('phpstorm')->openDir('/x/d');
})->throws(RuntimeException::class, 'Could not open with phpstorm: Unable to find application named PhpStorm');
