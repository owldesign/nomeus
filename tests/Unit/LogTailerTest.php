<?php

use App\Services\LogTailer;
use App\Support\NomeusConfig;
use App\Support\Editor;
use App\Support\Shell;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/nomeus-tail-'.uniqid();
    mkdir($this->dir);
    $this->file = "{$this->dir}/laravel.log";
    file_put_contents("{$this->dir}/config.json", json_encode(['ide' => 'phpstorm']));
    $config = new NomeusConfig("{$this->dir}/config.json");
    $this->tailer = new LogTailer(new Editor(new Shell($config), $config));
    $this->entry = fn (string $ts, string $level, string $msg) => "[{$ts}] local.{$level}: {$msg}\n";
});

afterEach(function () {
    @unlink($this->file); @unlink("{$this->dir}/config.json"); @rmdir($this->dir);
});

it('reads the tail first, aligned to an entry, then only what was appended', function () {
    $lines = '';
    for ($i = 0; $i < 30; $i++) {
        $lines .= ($this->entry)(sprintf('2026-09-02 01:00:%02d', $i), 'INFO', str_repeat('x', 40)." #{$i}");
    }
    file_put_contents($this->file, $lines);

    $first = $this->tailer->read($this->file, null, initialBytes: 400);
    expect($first['truncated'])->toBeTrue()
        ->and($first['reset'])->toBeFalse()
        ->and($first['entries'])->not->toBeEmpty()
        ->and($first['entries'][0]['message'])->toMatch('/^x{40} #\d+$/')            // aligned: no half line
        ->and(end($first['entries'])['message'])->toEndWith('#29')
        ->and($first['offset'])->toBe(strlen($lines));

    $same = $this->tailer->read($this->file, $first['offset']);
    expect($same['entries'])->toBe([])->and($same['offset'])->toBe($first['offset']);

    file_put_contents($this->file, ($this->entry)('2026-09-02 01:01:00', 'ERROR', 'new one'), FILE_APPEND);
    $next = $this->tailer->read($this->file, $first['offset']);
    expect($next['entries'])->toHaveCount(1)
        ->and($next['entries'][0]['message'])->toBe('new one')
        ->and($next['offset'])->toBe(strlen($lines) + strlen(($this->entry)('2026-09-02 01:01:00', 'ERROR', 'new one')));
});

it('adds ide links to file refs, waits for a half-written entry, and starts over after truncation', function () {
    file_put_contents($this->file, "[2026-09-02 01:00:00] local.ERROR: boom {\"exception\":\"[object] (RuntimeException(code: 0): boom at /Users/me/app/Foo.php:12)\n[stacktrace]\n#0 /Users/me/routes/web.php(9): x()\n\"}\n[2026-09-02 01:00:01] local.INFO: half");

    $r = $this->tailer->read($this->file);
    expect($r['entries'])->toHaveCount(1)                                              // the unterminated INFO line is held back
        ->and($r['entries'][0]['refs'][0])->toBe(['text' => '/Users/me/app/Foo.php:12', 'file' => '/Users/me/app/Foo.php', 'line' => 12, 'url' => 'phpstorm://open?file='.rawurlencode('/Users/me/app/Foo.php').'&line=12'])
        ->and($r['entries'][0]['refs'][1]['url'])->toBe('phpstorm://open?file='.rawurlencode('/Users/me/routes/web.php').'&line=9');

    file_put_contents($this->file, "\n", FILE_APPEND);
    $r2 = $this->tailer->read($this->file, $r['offset']);
    expect($r2['entries'])->toHaveCount(1)->and($r2['entries'][0]['message'])->toBe('half');

    $this->tailer->truncate($this->file);
    expect(filesize($this->file))->toBe(0);
    file_put_contents($this->file, "[2026-09-02 01:00:02] local.INFO: fresh\n");
    $r3 = $this->tailer->read($this->file, $r2['offset']);                              // old offset > new size
    expect($r3['reset'])->toBeTrue()->and($r3['entries'][0]['message'])->toBe('fresh');
});
