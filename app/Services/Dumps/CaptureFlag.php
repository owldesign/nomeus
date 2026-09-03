<?php

namespace App\Services\Dumps;

use App\Support\NomeusConfig;

/** ~/.nomeus/dumps/capture — present means "route dumps to the server". Toggling restarts nothing. */
final class CaptureFlag
{
    public function __construct(private readonly NomeusConfig $config) {}

    public function path(): string
    {
        return $this->config->dir().'/dumps/capture';
    }

    public function isOn(): bool
    {
        clearstatcache(true, $this->path());

        return is_file($this->path());
    }

    public function on(): void
    {
        $dir = dirname($this->path());
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        touch($this->path());
    }

    public function off(): void
    {
        @unlink($this->path());
    }
}
