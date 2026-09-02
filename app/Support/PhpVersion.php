<?php

namespace App\Support;

/** One installed php@X.Y keg as devkit sees it. */
final readonly class PhpVersion
{
    public function __construct(
        public string $version,     // "8.3"
        public ?string $patch,      // "8.3.14"
        public bool $linked,        // the global version (`valet use`)
        public bool $fpm,           // an fpm for this version is answering
        public array $sites,        // site names served by this version (isolated, or global for the rest)
        public string $ini,         // <brew>/etc/php/8.3/php.ini
        public string $confd,       // <brew>/etc/php/8.3/conf.d
        public ?string $outdated,   // newer patch available, or null
    ) {}

    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'patch' => $this->patch,
            'linked' => $this->linked,
            'fpm' => $this->fpm,
            'sites' => $this->sites,
            'ini' => $this->ini,
            'confd' => $this->confd,
            'outdated' => $this->outdated,
        ];
    }
}
