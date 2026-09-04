<?php

namespace App\Support;

use RuntimeException;

/** One managed service instance: ~/.nomeus/services/<name>/ with service.json, data/, conf/, run/, logs/. */
final readonly class ServiceInstance
{
    public function __construct(
        public string $name,
        public string $type,        // postgresql | mysql | redis | …
        public string $formula,     // postgresql@17
        public string $version,     // 17.6 (keg version at creation)
        public int $port,
        public string $dir,         // instance root
        public string $createdAt,
        public array $options = [], // driver-specific extras
    ) {}

    public static function fromArray(array $a): self
    {
        return new self(
            name: (string) $a['name'],
            type: (string) $a['type'],
            formula: (string) $a['formula'],
            version: (string) ($a['version'] ?? ''),
            port: (int) $a['port'],
            dir: (string) $a['dir'],
            createdAt: (string) ($a['created_at'] ?? ''),
            options: (array) ($a['options'] ?? []),
        );
    }

    public static function load(string $dir): ?self
    {
        $file = rtrim($dir, '/').'/service.json';
        if (! is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data)) {
            throw new RuntimeException("Invalid JSON in {$file}");
        }

        return self::fromArray($data + ['dir' => rtrim($dir, '/')]);
    }

    public function save(): void
    {
        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }
        file_put_contents($this->file(), json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", LOCK_EX);
    }

    public function with(array $changes): self
    {
        return self::fromArray($changes + $this->toArray());
    }

    public function file(): string
    {
        return "{$this->dir}/service.json";
    }

    public function dataDir(): string
    {
        return "{$this->dir}/data";
    }

    public function confDir(): string
    {
        return "{$this->dir}/conf";
    }

    public function runDir(): string
    {
        return "{$this->dir}/run";
    }

    public function logDir(): string
    {
        return "{$this->dir}/logs";
    }

    public function logFile(): string
    {
        return "{$this->dir}/logs/service.log";
    }

    public function label(): string
    {
        return 'dev.nomeus.svc.'.$this->name;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'formula' => $this->formula,
            'version' => $this->version,
            'port' => $this->port,
            'dir' => $this->dir,
            'created_at' => $this->createdAt,
            'options' => $this->options,
        ];
    }
}
