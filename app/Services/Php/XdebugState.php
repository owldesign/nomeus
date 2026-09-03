<?php

namespace App\Services\Php;

use App\Support\NomeusConfig;

/** ~/.nomeus/php/xdebug.json — per PHP version: where xdebug.so is and which mode we last wrote. */
final class XdebugState
{
    public const MODES = ['off', 'on', 'trigger'];

    public function __construct(private readonly NomeusConfig $config) {}

    public function path(): string
    {
        return $this->config->dir().'/php/xdebug.json';
    }

    /** @return array<string, array{so:string, mode:string}> */
    public function all(): array
    {
        if (! is_file($this->path())) {
            return [];
        }

        return (array) json_decode((string) file_get_contents($this->path()), true);
    }

    /** @return array{so:string, mode:string}|null */
    public function get(string $version): ?array
    {
        return $this->all()[$version] ?? null;
    }

    public function set(string $version, string $so, string $mode): void
    {
        $all = $this->all();
        $all[$version] = ['so' => $so, 'mode' => $mode];
        $dir = dirname($this->path());
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->path(), json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    public function forget(string $version): void
    {
        $all = $this->all();
        unset($all[$version]);
        file_put_contents($this->path(), json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
