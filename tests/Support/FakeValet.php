<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;

/**
 * Builds a ~/.config/valet look-alike in a temp dir, laid out exactly as Valet 4.12 writes it,
 * plus a fake <brew>/bin/valet → package dir with cli/valet.php so version() never runs Valet.
 */
final class FakeValet
{
    public readonly string $root;      // temp root
    public readonly string $configDir; // <root>/valet
    public readonly string $sitesRoot; // <root>/code — a "parked" directory

    public function __construct(private readonly string $tld = 'test', string $version = '4.12.0')
    {
        $this->root = sys_get_temp_dir().'/devkit-valet-'.uniqid();
        $this->configDir = "{$this->root}/valet";
        $this->sitesRoot = "{$this->root}/code";

        foreach (['valet/Sites', 'valet/Certificates', 'valet/Nginx', 'code', 'pkg/cli', 'bin'] as $d) {
            mkdir("{$this->root}/$d", 0755, true);
        }
        file_put_contents("{$this->configDir}/config.json", json_encode([
            'tld' => $tld,
            'loopback' => '127.0.0.1',
            'paths' => ["{$this->configDir}/Sites", $this->sitesRoot],
        ]));
        file_put_contents("{$this->root}/pkg/valet", "#!/bin/sh\necho stub\n");
        chmod("{$this->root}/pkg/valet", 0755);
        file_put_contents("{$this->root}/pkg/cli/valet.php", "<?php\n\$version = '{$version}';\n");
        symlink("{$this->root}/pkg/valet", "{$this->root}/bin/valet");
    }

    public function valetBin(): string
    {
        return "{$this->root}/bin/valet";
    }

    /** A directory under the parked path. Pass laravel: true to drop an artisan file in it. */
    public function parked(string $name, bool $laravel = false): string
    {
        $dir = "{$this->sitesRoot}/{$name}";
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if ($laravel) {
            file_put_contents("$dir/artisan", "#!/usr/bin/env php\n<?php\n");
        }

        return $dir;
    }

    /** Sites/<name> → $target (defaults to a fresh directory outside the parked path). */
    public function linked(string $name, ?string $target = null): string
    {
        $target ??= "{$this->root}/elsewhere/{$name}";
        if (! is_dir($target)) {
            mkdir($target, 0755, true);
        }
        symlink($target, "{$this->configDir}/Sites/{$name}");

        return $target;
    }

    public function secured(string $name): void
    {
        foreach (['crt', 'key', 'csr', 'conf'] as $ext) {
            touch("{$this->configDir}/Certificates/{$name}.{$this->tld}.{$ext}");
        }
    }

    public function isolated(string $name, string $php): void
    {
        file_put_contents(
            "{$this->configDir}/Nginx/{$name}.{$this->tld}",
            "# ISOLATED_PHP_VERSION=php@{$php}\nserver {\n    listen 127.0.0.1:80;\n    server_name {$name}.{$this->tld};\n}\n",
        );
    }

    public function proxied(string $name, string $upstream): void
    {
        file_put_contents(
            "{$this->configDir}/Nginx/{$name}.{$this->tld}",
            "# valet stub: proxy.valet.conf\nserver {\n    listen 127.0.0.1:80;\n    server_name {$name}.{$this->tld};\n    location / {\n        proxy_pass {$upstream};\n    }\n}\n",
        );
    }

    public function destroy(): void
    {
        File::deleteDirectory($this->root);
    }
}
