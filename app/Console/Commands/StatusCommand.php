<?php

namespace App\Console\Commands;

use App\Services\StatusService;
use Illuminate\Console\Command;

class StatusCommand extends Command
{
    protected $signature = 'status
        {--json : Emit the snapshot as JSON}
        {--diagnose : JSON snapshot plus env and raw subprocess output — compare with /api/status?diagnose=1}';

    protected $description = 'Show devkit, Valet, PHP and service status';

    public function handle(StatusService $status): int
    {
        $s = $status->snapshot();

        if ($this->option('diagnose')) {
            $s['diagnostics'] = $status->diagnostics();
        }
        if ($this->option('json') || $this->option('diagnose')) {
            $this->line(json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $state = fn (bool $up): string => $up ? '<fg=green>running</>' : '<fg=red>stopped</>';
        $fpm = $s['services']['php_fpm'];
        $site = config('devkit.site');

        $valet = $s['valet']['installed']
            ? sprintf('%s   tld .%s   paths: %s',
                $s['valet']['version'] ?? '?',
                $s['valet']['tld'],
                $s['valet']['paths'] ? implode(', ', $s['valet']['paths']) : '<fg=yellow>none parked</>')
            : '<fg=red>not installed</>';

        $this->table([], [
            ['home', $s['devkit']['home']],
            ['config', $s['devkit']['config_path'].($s['devkit']['config_exists'] ? '' : '   <fg=yellow>missing — run install/install.sh</>')],
            ['code dir', $s['devkit']['code_dir']],
            ['valet', $valet],
            ['php', $s['php']['global'] ?? '<fg=red>not found on PATH</>'],
            ['nginx', $state($s['services']['nginx'])],
            ['dnsmasq', $state($s['services']['dnsmasq'])],
            ['php-fpm', $fpm ? '<fg=green>running</> '.implode(', ', $fpm) : '<fg=red>stopped</>'],
            ['mailpit', $state($s['services']['mailpit'])],
            ['dashboard', $s['dashboard']['url'].'   '.($s['dashboard']['linked']
                ? '<fg=green>linked</>'
                : "<fg=yellow>not linked — run: devkit link {$site}</>")],
        ]);

        return self::SUCCESS;
    }
}
