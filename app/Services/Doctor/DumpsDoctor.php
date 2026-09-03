<?php

namespace App\Services\Doctor;

use App\Services\Dumps\CaptureFlag;
use App\Services\Dumps\PrependInstaller;
use App\Services\ServiceManager;

final class DumpsDoctor implements Section
{
    public function __construct(
        private readonly ServiceManager $services,
        private readonly CaptureFlag $flag,
        private readonly PrependInstaller $prepend,
    ) {}

    public function name(): string
    {
        return 'dumps';
    }

    public function checks(): array
    {
        $r = new Rows;
        $instance = null;
        foreach ($this->services->all() as $i) {
            if ($i->type === 'dumps') {
                $instance = $i;
            }
        }
        if ($instance === null) {
            $r->warn('server', 'no dump server instance — nomeus services:create dumps');
        } else {
            $st = $this->services->status($instance);
            $r->expect($st['running'], 'server', "{$instance->name} on {$instance->port}", "{$instance->name} stopped — nomeus services:start {$instance->name}", 'warn');
        }
        $r->expect($this->prepend->prependCurrent(), 'prepend', $this->prepend->prependPath(), 'prepend file missing/outdated — nomeus dumps:install', 'warn');
        $r->ok('capture', $this->flag->isOn() ? 'on — dump()/dd() go to the Debug page' : 'off — dumps print as usual');

        return $r->all();
    }
}
