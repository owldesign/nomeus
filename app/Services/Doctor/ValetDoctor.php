<?php

namespace App\Services\Doctor;

use App\Services\ValetBridge;
use App\Support\Probe;
use App\Support\Shell;

final class ValetDoctor implements Section
{
    public function __construct(private readonly ValetBridge $valet, private readonly Shell $shell, private readonly Probe $probe) {}

    public function name(): string
    {
        return 'valet';
    }

    public function checks(): array
    {
        $r = new Rows;
        if (! $this->valet->isInstalled()) {
            return $r->fail('installed', 'valet is not installed — composer global require laravel/valet && valet install')->all();
        }
        $r->ok('installed', 'valet '.($this->valet->version() ?? '?').' · tld .'.$this->valet->tld().' · loopback '.$this->valet->loopback());
        $r->expect($this->valet->isTrusted(), 'trusted', 'sudoers allow valet and brew without a password', 'not trusted — dashboard actions that need sudo will fail: valet trust');
        // nginx rewrites its process title on macOS, so pgrep -x can miss it: answer on :80 first (same as the status strip).
        $r->expect($this->probe->tcp($this->valet->loopback(), 80) || $this->shell->running('nginx'), 'nginx', 'answering on '.$this->valet->loopback().':80', 'not answering on :80 and no process — valet restart nginx');
        $r->expect($this->shell->running('dnsmasq'), 'dnsmasq', 'running', 'not running — valet restart dnsmasq');
        $r->expect($this->valet->paths() !== [], 'parked', implode(', ', $this->valet->paths()) ?: '—', 'no parked directories — valet park in your sites folder', 'warn');

        return $r->all();
    }
}
