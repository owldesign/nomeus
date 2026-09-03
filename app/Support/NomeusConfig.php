<?php

namespace App\Support;

use Illuminate\Support\Arr;
use RuntimeException;

/** Read/write access to ~/.nomeus/config.json. Cached per request; set() writes through. */
final class NomeusConfig
{
    private ?array $cache = null;

    public function __construct(private readonly string $path) {}

    /** php-fpm strips HOME; fall back to the passwd entry for the running user. */
    public static function homeDir(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');
        if (is_string($home) && $home !== '') {
            return rtrim($home, '/');
        }
        if (function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid(posix_geteuid());
            if (! empty($pw['dir'])) {
                return rtrim($pw['dir'], '/');
            }
        }
        throw new RuntimeException('Cannot determine the home directory (HOME unset, posix unavailable).');
    }

    public static function defaultPath(): string
    {
        return self::homeDir().'/.nomeus/config.json';
    }

    /** Expand a leading ~ to the home directory. */
    public static function expand(string $path): string
    {
        return str_starts_with($path, '~') ? self::homeDir().substr($path, 1) : $path;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function dir(): string
    {
        return dirname($this->path);
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        if (! $this->exists()) {
            return $this->cache = [];
        }
        $decoded = json_decode((string) file_get_contents($this->path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Invalid JSON in {$this->path}");
        }

        return $this->cache = $decoded;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->all(), $key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        $data = $this->all();
        Arr::set($data, $key, $value);
        $this->write($data);
    }

    public function write(array $data): void
    {
        if (! is_dir($this->dir())) {
            mkdir($this->dir(), 0755, true);
        }
        file_put_contents(
            $this->path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            LOCK_EX,
        );
        $this->cache = $data;
    }

    public function codeDir(): string
    {
        return self::expand((string) $this->get('code_dir', '~/Code'));
    }
}
