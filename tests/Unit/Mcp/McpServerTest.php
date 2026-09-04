<?php

use App\Services\Mcp\McpServer;
use App\Services\Mcp\ToolRegistry;

/** Protocol shape only; the tools are stubbed. */
beforeEach(function () {
    $this->registry = new class extends ToolRegistry
    {
        public function __construct() {}   // no container needed

        public function has(string $name): bool
        {
            return in_array($name, ['echo', 'boom'], true);
        }

        public function describe(): array
        {
            return [['name' => 'echo', 'description' => 'echoes', 'inputSchema' => ['type' => 'object', 'properties' => (object) ['text' => ['type' => 'string']], 'required' => ['text']]]];
        }

        public function call(string $name, array $args): mixed
        {
            if ($name === 'boom') {
                throw new RuntimeException('it broke');
            }
            if (! isset($args['text'])) {
                throw new InvalidArgumentException('echo: missing required argument [text]');
            }

            return ['you_said' => $args['text']];
        }
    };
    $this->server = new McpServer($this->registry, '1.4.0');
    $this->rpc = fn (array $m) => json_decode($this->server->handleLine(json_encode($m)) ?? 'null', true);
});

it('handshakes, lists tools, calls one, and shapes errors per the spec', function () {
    $init = ($this->rpc)(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '0']]]);
    expect($init['result']['protocolVersion'])->toBe(McpServer::PROTOCOL)
        ->and($init['result']['serverInfo'])->toBe(['name' => 'nomeus', 'version' => '1.4.0'])
        ->and($init['result']['capabilities']['tools'])->toBeArray()
        ->and($init['id'])->toBe(1);

    expect($this->server->handleLine(json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])))->toBeNull();   // notification: no reply
    expect(($this->rpc)(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'])['result'])->toBe([]);

    $list = ($this->rpc)(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list']);
    expect($list['result']['tools'][0]['name'])->toBe('echo')->and($list['result']['tools'][0]['inputSchema']['required'])->toBe(['text']);

    $call = ($this->rpc)(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['text' => 'hi']]]);
    expect($call['result']['isError'])->toBeFalse()->and($call['result']['content'][0])->toBe(['type' => 'text', 'text' => "{\n    \"you_said\": \"hi\"\n}"]);

    // a tool that throws is a result the model can read, not a protocol error
    $boom = ($this->rpc)(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'boom']]);
    expect($boom['result']['isError'])->toBeTrue()->and($boom['result']['content'][0]['text'])->toBe('it broke');

    // protocol errors
    expect(($this->rpc)(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'nope']])['error']['code'])->toBe(-32602)
        ->and(($this->rpc)(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => []]])['error']['code'])->toBe(-32602)
        ->and(($this->rpc)(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'resources/list'])['error']['code'])->toBe(-32601)
        ->and(json_decode($this->server->handleLine('{not json'), true)['error']['code'])->toBe(-32700)
        ->and(json_decode($this->server->handleLine('{"id":9}'), true)['error']['code'])->toBe(-32600);
});

it('serves a stream: one line in, one line out, notifications silent', function () {
    $in = fopen('php://memory', 'r+');
    fwrite($in, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])."\n\n".json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])."\n".json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])."\n");
    rewind($in);
    $out = fopen('php://memory', 'r+');

    $this->server->run($in, $out);
    rewind($out);
    $lines = array_values(array_filter(explode("\n", stream_get_contents($out))));

    expect($lines)->toHaveCount(2)
        ->and(json_decode($lines[0], true)['id'])->toBe(1)
        ->and(json_decode($lines[1], true)['result']['tools'][0]['name'])->toBe('echo');
});

it('does not treat an idle read timeout as EOF', function () {
    // php://memory can't time out; a socketpair can: nothing written for the timeout window, then a message.
    if (! function_exists('stream_socket_pair')) {
        $this->markTestSkipped('no stream_socket_pair');
    }
    [$client, $serverSide] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $out = fopen('php://memory', 'r+');
    $served = null;
    $pid = pcntl_fork();
    if ($pid === 0) {                                             // child: the server, on the socket end
        fclose($client);
        stream_set_timeout($serverSide, 1);                       // a 1-second "default_socket_timeout"
        $this->server->run($serverSide, $out);                    // must survive the idle second below
        rewind($out);
        $lines = array_values(array_filter(explode("\n", stream_get_contents($out))));
        exit(count($lines) === 1 && json_decode($lines[0], true)['id'] === 7 ? 0 : 1);
    }
    fclose($serverSide);
    sleep(2);                                                     // longer than the child's read timeout
    fwrite($client, json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping'])."\n");
    fclose($client);                                              // now a real EOF
    pcntl_waitpid($pid, $status);

    expect(pcntl_wexitstatus($status))->toBe(0);
})->skip(! function_exists('pcntl_fork'), 'pcntl not available');
