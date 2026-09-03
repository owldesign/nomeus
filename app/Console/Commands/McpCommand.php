<?php

namespace App\Console\Commands;

use App\Services\Mcp\McpServer;
use App\Services\Mcp\ToolRegistry;
use Illuminate\Console\Command;

class McpCommand extends Command
{
    protected $signature = 'mcp
        {--list : print the tools instead of serving}
        {--call= : call one tool and print the result (with --args)}
        {--args= : JSON arguments for --call}';

    protected $description = 'Serve the Model Context Protocol over stdio (for Claude Desktop, Claude Code, Cursor) — see nomeus mcp:install';

    public function handle(ToolRegistry $tools): int
    {
        $server = new McpServer($tools, (string) config('nomeus.version'));

        if ($this->option('list')) {
            $this->table(['tool', 'description'], array_map(fn ($t) => [$t['name'], wordwrap($t['description'], 90)], $tools->describe()));

            return self::SUCCESS;
        }
        if ($name = $this->option('call')) {
            $args = json_decode((string) ($this->option('args') ?: '{}'), true);
            if (! is_array($args)) {
                $this->error('--args must be a JSON object');

                return self::FAILURE;
            }
            $reply = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args]]);
            if (isset($reply['error'])) {
                $this->error("{$reply['error']['code']}: {$reply['error']['message']}");

                return self::FAILURE;
            }
            $this->line($reply['result']['content'][0]['text'] ?? '');   // the tool's text, as the model would read it

            return ($reply['result']['isError'] ?? false) ? self::FAILURE : self::SUCCESS;
        }

        // stdio: STDOUT is the protocol channel — nothing else may write there.
        ini_set('display_errors', 'stderr');
        $server->run(STDIN, STDOUT);

        return self::SUCCESS;
    }
}
