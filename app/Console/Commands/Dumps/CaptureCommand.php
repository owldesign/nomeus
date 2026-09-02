<?php

namespace App\Console\Commands\Dumps;

use App\Services\Dumps\CaptureFlag;
use Illuminate\Console\Command;

class CaptureCommand extends Command
{
    protected $signature = 'dumps:capture {state? : on | off; omit to show}';

    protected $description = 'Route dump()/dd() and recorded events to the dump server (on) or back to the browser/terminal (off)';

    public function handle(CaptureFlag $flag): int
    {
        $state = $this->argument('state');
        if ($state === 'on') {
            $flag->on();
        } elseif ($state === 'off') {
            $flag->off();
        } elseif ($state !== null) {
            $this->error('on or off');

            return self::FAILURE;
        }
        $this->line($flag->isOn() ? '<fg=green>capture on</> — dumps go to the Debug page' : '<fg=gray>capture off</> — dumps print as usual');

        return self::SUCCESS;
    }
}
