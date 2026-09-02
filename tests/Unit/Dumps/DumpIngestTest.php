<?php

use App\Services\Dumps\DumpIngest;
use Symfony\Component\VarDumper\Cloner\VarCloner;

// The client package isn't in devkit's autoloader; load the one class the round-trip needs.
// (File scope runs before the app boots, so no base_path() here.)
require_once __DIR__.'/../../../packages/devkit-client/src/Dumps/Sender.php';

beforeEach(fn () => $this->ingest = new DumpIngest);

it('turns a plain dump with symfony context into a dump row with html and text', function () {
    $data = (new VarCloner)->cloneVar(['name' => 'devkit', 'n' => 3]);
    $row = $this->ingest->toRow($data, [
        'source' => ['name' => 'web.php', 'file' => '/Users/me/Sites/smoke/routes/web.php', 'line' => 29],
        'request' => ['uri' => 'http://smoke.test/', 'method' => 'GET', 'identifier' => 'abc'],
    ]);

    expect($row)->toMatchArray(['kind' => 'dump', 'request_key' => 'abc', 'uri' => 'http://smoke.test/', 'method' => 'GET', 'file' => '/Users/me/Sites/smoke/routes/web.php', 'line' => 29, 'payload' => null])
        ->and($row['text'])->toContain('"name" => "devkit"')
        ->and($row['html'])->toContain('sf-dump')
        ->and($row['html'])->toContain('Sfdump(')
        ->and($row['html'])->not->toContain('<style>');   // header is served separately
    expect(DumpIngest::header())->toContain('<style>')->and(DumpIngest::header())->toContain('Sfdump');
});

it('prefers the client package\'s request id and command context', function () {
    $data = (new VarCloner)->cloneVar('x');
    $row = $this->ingest->toRow($data, ['devkit' => ['request_id' => 'req-9'], 'request' => ['identifier' => 'abc', 'uri' => '/u'], 'cli' => ['command_line' => 'artisan tinker']]);
    expect($row['request_key'])->toBe('req-9')->and($row['command'])->toBe('artisan tinker');
});

it('round-trips the client package\'s frames for every recorded kind', function () {
    $_SERVER['DEVKIT_REQUEST_ID'] = 'req-1';
    $_SERVER['REQUEST_URI'] = '/orders';
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $cases = [
        ['query', ['sql' => 'select * from users where id = ?', 'bindings' => [1], 'ms' => 1.5, 'connection' => 'pgsql'], ['file' => '/app/Http/Controllers/X.php', 'line' => 12], 'select * from users where id = ?  — 1.5 ms'],
        ['job', ['status' => 'processed', 'name' => 'App\\Jobs\\Sync', 'queue' => 'default', 'ms' => 40.2], [], 'processed App\\Jobs\\Sync on default'],
        ['view', ['name' => 'orders.index', 'path' => '/app/resources/views/orders/index.blade.php'], [], 'orders.index'],
        ['request', ['method' => 'GET', 'url' => 'https://api.example.test/x', 'status' => 200, 'ms' => 88.0], [], 'GET https://api.example.test/x → 200'],
        ['log', ['level' => 'warning', 'message' => 'careful', 'context' => []], [], 'WARNING careful'],
    ];
    foreach ($cases as [$kind, $payload, $ctx, $summary]) {
        $line = \Zhuk\DevkitClient\Dumps\Sender::frame($kind, $payload, $ctx);
        [$data, $context] = DumpIngest::decode($line);
        $row = $this->ingest->toRow($data, $context);

        expect($row['kind'])->toBe($kind)
            ->and($row['request_key'])->toBe('req-1')
            ->and($row['uri'])->toBe('/orders')
            ->and($row['method'])->toBe('POST')
            ->and($row['text'])->toBe($summary)
            ->and(json_decode($row['payload'], true))->toBe($payload);
        if ($ctx) {
            expect($row['file'])->toBe($ctx['file'])->and($row['line'])->toBe($ctx['line']);
        }
    }
    expect(DumpIngest::decode('not a frame'))->toBeNull()
        ->and(DumpIngest::decode(base64_encode(serialize(['x']))))->toBeNull();
    unset($_SERVER['DEVKIT_REQUEST_ID'], $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
});
