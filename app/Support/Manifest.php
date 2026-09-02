<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * dev.yml — what a site needs from devkit. Herd's herd.yml equivalent.
 *
 *   name: smoke                     # APP_NAME + mail tag; defaults to the directory name
 *   domain: smoke                   # site name (no tld); defaults to the directory name
 *   php: "8.4"                      # isolate the site to this version
 *   node: "22"                      # written to .nvmrc
 *   secure: true                    # valet secure
 *   services:
 *     - { type: postgresql, version: "17", instance: pg17, database: smoke }
 *     - { type: redis }
 *     - { type: seaweedfs, bucket: smoke }
 *   mail: true                      # mailpit instance + MAIL_* + the client package
 *   client: true                    # zhuk/devkit-client (implied by mail)
 *   env: { QUEUE_CONNECTION: redis }
 *   post-init:
 *     - composer install
 *     - php artisan migrate
 */
final readonly class Manifest
{
    public const FILE = 'dev.yml';

    /** @param  list<array{type:string, version:?string, instance:?string, database:?string, bucket:?string}>  $services */
    public function __construct(
        public string $path,
        public string $name,
        public string $domain,
        public ?string $php,
        public ?string $node,
        public bool $secure,
        public array $services,
        public bool $mail,
        public bool $client,
        public array $env,
        public array $postInit,
    ) {}

    public static function exists(string $dir): bool
    {
        return is_file(rtrim($dir, '/').'/'.self::FILE);
    }

    public static function load(string $dir): self
    {
        $dir = rtrim($dir, '/');
        $file = "{$dir}/".self::FILE;
        if (! is_file($file)) {
            throw new RuntimeException("No ".self::FILE." in {$dir}. Start from docs/dev.yml.example.");
        }
        try {
            $data = Yaml::parseFile($file) ?? [];
        } catch (\Throwable $e) {
            throw new RuntimeException(self::FILE.": {$e->getMessage()}");
        }
        if (! is_array($data)) {
            throw new RuntimeException(self::FILE.' must be a mapping.');
        }

        return self::fromArray($data, $dir);
    }

    public static function fromArray(array $data, string $dir): self
    {
        $base = basename($dir);
        $slug = fn (string $v) => strtolower(trim($v));

        foreach (['name', 'domain', 'php', 'node'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] !== null && ! is_scalar($data[$k])) {
                throw new RuntimeException(self::FILE.": `{$k}` must be a string.");
            }
        }
        $domain = $slug((string) ($data['domain'] ?? $base));
        if (! preg_match('/^[a-z0-9][a-z0-9.-]*$/', $domain)) {
            throw new RuntimeException(self::FILE.": `domain` [{$domain}] is not a valid site name.");
        }
        $php = isset($data['php']) ? (string) $data['php'] : null;
        if ($php !== null && ! preg_match('/^\d+\.\d+$/', $php)) {
            throw new RuntimeException(self::FILE.": `php` must look like 8.4, got [{$php}].");
        }

        $services = [];
        foreach ((array) ($data['services'] ?? []) as $i => $svc) {
            if (is_string($svc)) {
                $svc = ['type' => $svc];
            }
            if (! is_array($svc) || empty($svc['type'])) {
                throw new RuntimeException(self::FILE.": services[{$i}] needs a `type`.");
            }
            $services[] = [
                'type' => (string) $svc['type'],
                'version' => isset($svc['version']) ? (string) $svc['version'] : null,
                'instance' => isset($svc['instance']) ? (string) $svc['instance'] : null,
                'database' => isset($svc['database']) ? (string) $svc['database'] : null,
                'bucket' => isset($svc['bucket']) ? (string) $svc['bucket'] : null,
            ];
        }

        $env = (array) ($data['env'] ?? []);
        foreach ($env as $k => $v) {
            if (! preg_match('/^[A-Z_][A-Z0-9_]*$/', (string) $k) || ! is_scalar($v) && $v !== null) {
                throw new RuntimeException(self::FILE.": env key [{$k}] must be UPPER_SNAKE with a scalar value.");
            }
        }
        $post = array_values(array_map('strval', (array) ($data['post-init'] ?? $data['post_init'] ?? [])));
        $mail = (bool) ($data['mail'] ?? false);

        return new self(
            path: $dir,
            name: (string) ($data['name'] ?? $base),
            domain: $domain,
            php: $php,
            node: isset($data['node']) ? (string) $data['node'] : null,
            secure: (bool) ($data['secure'] ?? false),
            services: $services,
            mail: $mail,
            client: (bool) ($data['client'] ?? $mail),
            env: array_map(fn ($v) => $v === null ? '' : (string) $v, $env),
            postInit: $post,
        );
    }
}
