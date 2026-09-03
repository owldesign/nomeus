<?php

namespace App\Console\Commands;

use App\Support\NomeusConfig;
use App\Support\Shell;
use Illuminate\Console\Command;

/** Registers `nomeus mcp` with a client: prints the snippet, or writes it with --write. */
class McpInstallCommand extends Command
{
    protected $signature = 'mcp:install {client : claude | code | cursor} {--write : write the client config instead of printing the snippet}';

    protected $description = 'Register nomeus as an MCP server in Claude Desktop, Claude Code or Cursor';

    public function handle(Shell $shell): int
    {
        $shim = $shell->which('nomeus') ?: base_path('bin/nomeus');
        $entry = ['command' => $shim, 'args' => ['mcp']];
        $home = NomeusConfig::homeDir();

        switch ($this->argument('client')) {
            case 'claude':
                $file = "{$home}/Library/Application Support/Claude/claude_desktop_config.json";

                return $this->mergeJson($file, ['mcpServers' => ['nomeus' => $entry]], 'restart Claude Desktop; nomeus appears under the tools icon');
            case 'cursor':
                $file = "{$home}/.cursor/mcp.json";

                return $this->mergeJson($file, ['mcpServers' => ['nomeus' => $entry]], 'Cursor → Settings → MCP shows nomeus');
            case 'code':
                $cmd = "claude mcp add nomeus -- {$shim} mcp";
                if ($this->option('write')) {
                    $r = $shell->run(['claude', 'mcp', 'add', 'nomeus', '--', $shim, 'mcp'], timeout: 30);
                    if (! $r->successful()) {
                        $this->error('claude mcp add failed: '.trim($r->errorOutput() ?: $r->output()).' — is the Claude Code CLI installed?');

                        return self::FAILURE;
                    }
                    $this->info('registered with Claude Code');
                } else {
                    $this->line($cmd);
                    $this->line('<fg=gray>(or: nomeus mcp:install code --write)</>');
                }

                return self::SUCCESS;
            default:
                $this->error('client: claude, code or cursor');

                return self::FAILURE;
        }
    }

    private function mergeJson(string $file, array $add, string $then): int
    {
        if (! $this->option('write')) {
            $this->line("<fg=gray>{$file}</>");
            $this->line(json_encode($add, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->line("<fg=gray>merge that in, or: nomeus mcp:install {$this->argument('client')} --write</>");

            return self::SUCCESS;
        }
        $current = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        if (! is_array($current)) {
            $this->error("{$file} is not valid JSON — fix it by hand first.");

            return self::FAILURE;
        }
        $current['mcpServers'] = ($current['mcpServers'] ?? []) + [];
        $current['mcpServers']['nomeus'] = $add['mcpServers']['nomeus'];
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        file_put_contents($file, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->info("wrote {$file} — {$then}");

        return self::SUCCESS;
    }
}
