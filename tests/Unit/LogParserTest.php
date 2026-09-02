<?php

use App\Support\LogParser;

$sample = <<<'LOG'
[2026-09-02 01:29:18] local.INFO: hello {"user":1}
[2026-09-02 01:30:00] local.ERROR: Call to undefined method Foo::bar() {"exception":"[object] (Error(code: 0): Call to undefined method Foo::bar() at /Users/me/Sites/smoke/app/Http/Controllers/Foo.php:12)
[stacktrace]
#0 /Users/me/Sites/smoke/routes/web.php(9): Foo->index()
#1 {main}
"}
[2026-09-02 01:31:00] local.WARNING: careful

LOG;

it('parses multi-line laravel entries with context, trace, refs and severity', function () use ($sample) {
    $r = LogParser::parse($sample);

    expect($r['consumed'])->toBe(strlen($sample))
        ->and($r['entries'])->toHaveCount(3);
    [$info, $error, $warn] = $r['entries'];
    expect($info)->toMatchArray(['ts' => '2026-09-02 01:29:18', 'env' => 'local', 'level' => 'info', 'severity' => 'info', 'message' => 'hello', 'context' => '{"user":1}', 'trace' => ''])
        ->and($error['message'])->toBe('Call to undefined method Foo::bar()')
        ->and($error['severity'])->toBe('error')
        ->and($error['context'])->toStartWith('{"exception":"[object] (Error(code: 0)')
        ->and($error['trace'])->toBe("[stacktrace]\n#0 /Users/me/Sites/smoke/routes/web.php(9): Foo->index()\n#1 {main}")
        ->and(array_map(fn ($r) => "{$r['file']}:{$r['line']}", $error['refs']))->toBe(['/Users/me/Sites/smoke/app/Http/Controllers/Foo.php:12', '/Users/me/Sites/smoke/routes/web.php:9'])
        ->and($warn)->toMatchArray(['level' => 'warning', 'severity' => 'warning', 'message' => 'careful']);
});

it('holds back an unterminated last entry when told the text may be mid-write', function () {
    $text = "[2026-09-02 01:29:18] local.INFO: done\n[2026-09-02 01:30:00] local.ERROR: half wri";

    $partial = LogParser::parse($text, complete: false);
    expect($partial['entries'])->toHaveCount(1)
        ->and($partial['consumed'])->toBe(strlen("[2026-09-02 01:29:18] local.INFO: done\n"));

    $all = LogParser::parse($text, complete: true);
    expect($all['entries'])->toHaveCount(2)->and($all['consumed'])->toBe(strlen($text));
});

it('understands nginx and php-fpm lines, and plain text', function () {
    $r = LogParser::parse("2026/09/02 01:00:00 [error] 123#0: *5 open() \"/x/public/nope\" failed (2: No such file)\n[02-Sep-2026 01:00:01] WARNING: [pool www] child 9 said into stderr\nsomething else entirely\n");

    expect($r['entries'])->toHaveCount(3)
        ->and($r['entries'][0])->toMatchArray(['ts' => '2026-09-02 01:00:00', 'env' => 'nginx', 'level' => 'error', 'severity' => 'error'])
        ->and($r['entries'][0]['message'])->toStartWith('123#0: *5 open()')
        ->and($r['entries'][1])->toMatchArray(['env' => 'php-fpm', 'level' => 'warning', 'severity' => 'warning'])
        ->and($r['entries'][2])->toMatchArray(['ts' => null, 'message' => 'something else entirely', 'severity' => 'info']);
});

it('finds where the first entry starts after a seek into the middle of one', function () {
    $text = "#3 /x/y.php(4): foo()\n\"}\n[2026-09-02 01:31:00] local.INFO: next\n";
    expect(LogParser::firstEntryOffset($text))->toBe(strlen("#3 /x/y.php(4): foo()\n\"}\n"))
        ->and(LogParser::firstEntryOffset("nothing here\n"))->toBeNull()
        ->and(LogParser::firstEntryOffset("[2026-09-02 01:31:00] local.INFO: first\n"))->toBe(0);
});
