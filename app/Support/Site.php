<?php

namespace App\Support;

/** One Valet site, however Valet knows about it. Immutable; built by ValetBridge. */
final readonly class Site
{
    public function __construct(
        public string $name,
        public string $type,        // parked | linked | proxy
        public string $path,        // directory for parked/linked, upstream URL for proxy
        public string $tld,
        public bool $secured,
        public ?string $php,        // isolated version "8.3", or null = global
        public ?string $nginxConf,  // ~/.config/valet/Nginx/<name>.<tld> when it exists
    ) {}

    public function host(): string
    {
        return "{$this->name}.{$this->tld}";
    }

    public function url(): string
    {
        return ($this->secured ? 'https' : 'http')."://{$this->host()}";
    }

    public function isLaravel(): bool
    {
        return $this->type !== 'proxy' && is_file("{$this->path}/artisan");
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'host' => $this->host(),
            'url' => $this->url(),
            'type' => $this->type,
            'path' => $this->path,
            'secured' => $this->secured,
            'php' => $this->php,
            'laravel' => $this->isLaravel(),
            'manifest' => $this->type !== 'proxy' && Manifest::exists($this->path),   // nomeus.yml or dev.yml
            'nginx_conf' => $this->nginxConf,
        ];
    }
}
