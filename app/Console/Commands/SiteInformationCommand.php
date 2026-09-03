<?php

namespace App\Console\Commands;

use App\Services\SiteInformation;
use App\Services\SiteResolver;
use Illuminate\Console\Command;

class SiteInformationCommand extends Command
{
    protected $signature = 'site-information {name? : Site name; defaults to the site containing the current directory} {--json : Emit as JSON}';

    protected $description = 'Show how Valet serves a site, plus `artisan about` for Laravel sites';

    public function handle(SiteResolver $resolver, SiteInformation $info): int
    {
        $site = $resolver->resolve($this->argument('name'), (string) getcwd());
        if ($site === null) {
            $this->error($this->argument('name')
                ? "Site [{$this->argument('name')}] is not parked, linked or proxied."
                : 'Current directory is not inside a Valet site. Pass a name: nomeus site-information <name>');

            return self::FAILURE;
        }

        $about = $info->about($site);

        if ($this->option('json')) {
            $this->line(json_encode($site->toArray() + ['about' => $about], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $rows = [
            ['site', $site->name],
            ['url', $site->url()],
            ['type', $site->type],
            ['path', $site->path],
            ['php', $site->php ? "{$site->php} (isolated)" : 'global'],
            ['tls', $site->secured ? 'yes' : 'no'],
            ['nginx conf', $site->nginxConf ?? '<fg=gray>none (served by Valet default)</>'],
        ];

        if ($about !== null) {
            $str = fn ($v): string => is_array($v) ? implode(' / ', array_map(fn ($x) => is_scalar($x) ? (string) $x : json_encode($x), $v)) : (string) $v;
            $env = $about['environment'] ?? [];
            $drivers = $about['drivers'] ?? [];
            $rows[] = ['', ''];
            $rows[] = ['laravel', $str($env['laravel_version'] ?? '?').'   php '.$str($env['php_version'] ?? '?')];
            $rows[] = ['env', $str($env['environment'] ?? '?').'   debug '.strtolower($str($env['debug_mode'] ?? '?'))];
            $rows[] = ['app url', $str($env['url'] ?? '?')];
            $rows[] = ['drivers', collect($drivers)->map(fn ($v, $k) => "$k=".$str($v))->implode('  ')];
        } elseif ($site->isLaravel()) {
            $rows[] = ['laravel', '<fg=yellow>artisan about failed — run it in the site to see why</>'];
        }

        $this->table([], $rows);

        return self::SUCCESS;
    }
}
