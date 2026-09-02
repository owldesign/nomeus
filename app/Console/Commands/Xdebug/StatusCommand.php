<?php

namespace App\Console\Commands\Xdebug;

use App\Services\Php\XdebugManager;
use Illuminate\Console\Command;

class StatusCommand extends Command
{
    protected $signature = 'xdebug {--json}';

    protected $description = 'Xdebug per PHP version: installed, mode, and whether the IDE is listening';

    public function handle(XdebugManager $xdebug): int
    {
        $status = $xdebug->status();
        $ide = $xdebug->ideListening();
        if ($this->option('json')) {
            $this->line(json_encode(['versions' => $status, 'ide_listening' => $ide, 'port' => $xdebug->port()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        if ($status === []) {
            $this->line('No PHP versions installed under brew.');

            return self::SUCCESS;
        }
        $this->table(['php', 'xdebug', 'mode', 'notes'], array_map(fn ($v, $s) => [
            $v,
            $s['installed'] ? '<fg=green>installed</>' : '<fg=gray>— (devkit xdebug:install '.$v.')</>',
            $s['installed'] ? match ($s['mode']) { 'on' => '<fg=yellow>on</>', 'trigger' => '<fg=blue>trigger</>', default => 'off' } : '',
            implode(' · ', array_filter([
                $s['tap_ini'] ? '<fg=red>formula ini reappeared — devkit xdebug:mode '.$s['mode'].' --php='.$v.'</>' : null,
                $s['installed'] && ! $s['ini_current'] ? '<fg=yellow>ini outdated — devkit dumps:install</>' : null,
            ])),
        ], array_keys($status), $status));
        $this->line($ide
            ? "<fg=green>IDE listening on 127.0.0.1:{$xdebug->port()}</>"
            : "<fg=gray>nothing listening on 127.0.0.1:{$xdebug->port()} — mode \"on\" would cost ~200 ms per request until it does</>");

        return self::SUCCESS;
    }
}
