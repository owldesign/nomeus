<?php

namespace App\Support;

use RuntimeException;

/**
 * Opens files and directories in the IDE named by config.json's "ide".
 * URL schemes carry a line number where the app supports one; `open -a` is the fallback.
 */
final class Editor
{
    public const APPS = [
        'phpstorm' => 'PhpStorm',
        'vscode' => 'Visual Studio Code',
        'cursor' => 'Cursor',
        'sublime' => 'Sublime Text',
        'zed' => 'Zed',
        'open' => null, // macOS default app for the file type
    ];

    public function __construct(
        private readonly Shell $shell,
        private readonly NomeusConfig $config,
    ) {}

    public function ide(): string
    {
        return (string) $this->config->get('ide', 'phpstorm');
    }

    /** @return list<string> argv that opens $path (optionally at $line) in the IDE */
    public function fileCommand(string $path, ?int $line = null): array
    {
        $at = $line ? ":{$line}" : '';

        return match ($this->ide()) {
            'phpstorm' => ['open', 'phpstorm://open?file='.rawurlencode($path).($line ? "&line={$line}" : '')],
            'vscode' => ['open', 'vscode://file'.$path.$at],
            'cursor' => ['open', 'cursor://file'.$path.$at],
            'sublime' => $this->cli('subl', 'Sublime Text', $path.$at, $path),
            'zed' => $this->cli('zed', 'Zed', $path.$at, $path),
            default => ['open', '-t', $path],
        };
    }

    /**
     * A URL the browser can open directly (the dashboard's file:line links). Null when the IDE
     * has no scheme ('open'); Sublime's needs its URL-scheme handler package installed.
     */
    public function fileUrl(string $path, ?int $line = null): ?string
    {
        $at = $line ? ":{$line}" : '';

        return match ($this->ide()) {
            'phpstorm' => 'phpstorm://open?file='.rawurlencode($path).($line ? "&line={$line}" : ''),
            'vscode' => 'vscode://file'.$path.$at,
            'cursor' => 'cursor://file'.$path.$at,
            'zed' => 'zed://file'.$path.$at,
            'sublime' => 'subl://open?url='.rawurlencode('file://'.$path).($line ? "&line={$line}" : ''),
            default => null,
        };
    }

    /** @return list<string> argv that opens $dir as a project in the IDE */
    public function dirCommand(string $dir): array
    {
        return match ($this->ide()) {
            'phpstorm' => ['open', '-a', 'PhpStorm', $dir],
            'vscode' => ['open', 'vscode://file'.$dir],
            'cursor' => ['open', 'cursor://file'.$dir],
            'sublime' => $this->cli('subl', 'Sublime Text', $dir, $dir),
            'zed' => $this->cli('zed', 'Zed', $dir, $dir),
            default => ['open', $dir],
        };
    }

    public function openFile(string $path, ?int $line = null): void
    {
        $this->exec($this->fileCommand($path, $line));
    }

    public function openDir(string $dir): void
    {
        $this->exec($this->dirCommand($dir));
    }

    /** CLI launcher with a :line-capable target when installed; otherwise `open -a App plainPath`. */
    private function cli(string $bin, string $app, string $target, string $plain): array
    {
        return $this->shell->which($bin) !== null ? [$bin, $target] : ['open', '-a', $app, $plain];
    }

    private function exec(array $argv): void
    {
        $result = $this->shell->run($argv, timeout: 30);
        if (! $result->successful()) {
            $detail = trim($result->errorOutput()) ?: trim($result->output());
            throw new RuntimeException("Could not open with {$this->ide()}: ".($detail ?: implode(' ', $argv)));
        }
    }
}
