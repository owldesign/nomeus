<?php

namespace App\Services\Mcp;

use JsonException;
use Throwable;

/**
 * Model Context Protocol server over newline-delimited JSON-RPC 2.0 (the stdio transport).
 * Speaks: initialize · notifications/initialized · ping · tools/list · tools/call.
 * In-process: tools call nomeus's own services, as the user running the client.
 */
final class McpServer
{
    public const PROTOCOL = '2025-06-18';

    public function __construct(private readonly ToolRegistry $tools, private readonly string $version = 'dev') {}

    /** Loop until $in closes. Each line in, at most one line out. */
    public function run($in, $out): void
    {
        while (($line = fgets($in)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $reply = $this->handleLine($line);
            if ($reply !== null) {
                fwrite($out, $reply."\n");
                fflush($out);
            }
        }
    }

    /** One JSON line → one JSON line, or null for notifications. */
    public function handleLine(string $line): ?string
    {
        try {
            $msg = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $this->encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error: '.$e->getMessage()]]);
        }
        if (! is_array($msg) || ($msg['jsonrpc'] ?? null) !== '2.0' || ! isset($msg['method']) || ! is_string($msg['method'])) {
            return $this->encode(['jsonrpc' => '2.0', 'id' => is_array($msg) ? ($msg['id'] ?? null) : null, 'error' => ['code' => -32600, 'message' => 'Invalid Request']]);
        }
        $reply = $this->handle($msg);

        return $reply === null ? null : $this->encode($reply);
    }

    /** @return array|null  null for notifications (no id) */
    public function handle(array $msg): ?array
    {
        $id = $msg['id'] ?? null;
        $isNotification = ! array_key_exists('id', $msg);
        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

        try {
            $result = match ($msg['method']) {
                'initialize' => [
                    'protocolVersion' => self::PROTOCOL,
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => 'nomeus', 'version' => $this->version],
                    'instructions' => 'Nomeus manages this Mac\'s local PHP stack: Valet sites, PHP versions, services (postgres, mysql, redis, …), mail, logs, dumps, Xdebug. Tools are read-mostly; the mutations are start/stop/restart of a service, Xdebug mode, and dump capture.',
                ],
                'ping' => new \stdClass,
                'tools/list' => ['tools' => $this->tools->describe()],
                'tools/call' => $this->call($params),
                'notifications/initialized', 'notifications/cancelled', 'notifications/progress' => null,
                default => throw new McpError(-32601, "Method not found: {$msg['method']}"),
            };
        } catch (McpError $e) {
            return $isNotification ? null : ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $e->getCode(), 'message' => $e->getMessage()]];
        } catch (Throwable $e) {
            return $isNotification ? null : ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => 'Internal error: '.$e->getMessage()]];
        }

        if ($isNotification || $result === null) {
            return null;
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function call(array $params): array
    {
        $name = $params['name'] ?? null;
        if (! is_string($name) || ! $this->tools->has($name)) {
            throw new McpError(-32602, 'Unknown tool: '.(is_string($name) ? $name : '(none)'));
        }
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        try {
            $value = $this->tools->call($name, $args);
        } catch (\InvalidArgumentException $e) {
            throw new McpError(-32602, $e->getMessage());
        } catch (Throwable $e) {
            // Tool failures are results, not protocol errors — the model should read them.
            return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
        }
        $text = is_string($value) ? $value : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false];
    }

    private function encode(array $msg): string
    {
        return json_encode($msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
