<?php

namespace App\Console\Commands;

use App\Services\BrewBridge;
use App\Services\ServiceManager;
use App\Services\ValetBridge;
use App\Support\Manifest;
use App\Support\Shell;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

/** The reverse of `nomeus new`: certificate, the site (directory or link), and with --db its databases. */
class RmCommand extends Command
{
    protected $signature = 'rm
        {site : site name (without the tld)}
        {--db : also drop the database/bucket its nomeus.yml names, on the instance it names}
        {--keep-dir : unlink/unsecure only; leave the directory in place}
        {--yes : no confirmation}';

    protected $description = 'Remove a site: unsecure, remove the directory (or unlink), optionally drop its database';

    public function handle(ValetBridge $valet, ServiceManager $services, BrewBridge $brew, Shell $shell): int
    {
        $name = (string) $this->argument('site');
        $site = $valet->find($name);
        if ($site === null) {
            $this->error("No site [{$name}].");

            return self::FAILURE;
        }
        if ($site->type === 'proxy') {
            $this->error("[{$name}] is a proxy — valet unproxy {$name}");

            return self::FAILURE;
        }
        if (realpath($site->path) === realpath(base_path())) {
            $this->error('That is nomeus itself.');

            return self::FAILURE;
        }

        $secured = in_array($name, $valet->secured(), true);
        $drops = [];
        if ($this->option('db') && Manifest::exists($site->path)) {
            try {
                $m = Manifest::load($site->path);
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            foreach ($m->services as $svc) {
                $target = $svc['database'] ?? $svc['bucket'] ?? null;
                if ($target === null) {
                    continue;
                }
                $instance = $svc['instance'] ? $services->find($svc['instance']) : null;
                if ($instance === null) {
                    foreach ($services->all() as $i) {
                        if ($i->type === $svc['type']) {
                            $instance = $i;
                            break;
                        }
                    }
                }
                if ($instance === null) {
                    $this->warn("no {$svc['type']} instance for {$target} — skipped");

                    continue;
                }
                $plan = $services->driver($instance)->dropDatabasePlan($instance, $brew->formulaBinDir($instance->formula) ?? '', $target);
                if ($plan !== null) {
                    $drops[] = ['instance' => $instance, 'plan' => $plan, 'target' => $target];
                }
            }
        }

        $this->line('<fg=yellow>plan</>');
        if ($secured) {
            $this->line("  unsecure {$name}");
        }
        $this->line($site->type === 'linked'
            ? "  unlink {$name}".($this->option('keep-dir') ? '' : "  (directory {$site->path} is removed too)")
            : ($this->option('keep-dir') ? "  keep {$site->path} (parked site stays served until the directory goes)" : "  rm -rf {$site->path}"));
        foreach ($drops as $d) {
            $this->line("  {$d['plan']['label']} on {$d['instance']->name}");
        }
        if (! $this->option('db') && Manifest::exists($site->path)) {
            $this->line('  <fg=gray>databases untouched (add --db to drop what nomeus.yml names)</>');
        }
        if (! $this->option('yes') && ! $this->confirm("Remove {$name}.{$valet->tld()}?", false)) {
            return self::SUCCESS;
        }

        $log = fn (string $l) => $this->line("<fg=gray>  {$l}</>");
        try {
            if ($secured) {
                $log($valet->unsecure($name));
            }
            if ($site->type === 'linked') {
                $log($valet->unlink($name));
            }
            foreach ($drops as $d) {
                $r = $shell->run($d['plan']['argv'], $d['plan']['cwd'], $d['plan']['timeout']);
                if (! $r->successful()) {
                    throw new RuntimeException("{$d['plan']['label']} failed: ".trim($r->errorOutput() ?: $r->output()));
                }
                $log("{$d['plan']['label']}: done");
            }
            if (! $this->option('keep-dir')) {
                File::deleteDirectory($site->path);
                $log("removed {$site->path}");
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("{$name}.{$valet->tld()} removed.");

        return self::SUCCESS;
    }
}
