<?php

namespace App\Console\Commands;

use App\Services\BrewBridge;
use App\Services\Dumps\PrependInstaller;
use App\Services\LaunchdManager;
use App\Services\Php\XdebugManager;
use App\Services\ServiceManager;
use App\Services\ValetBridge;
use App\Support\NomeusConfig;
use App\Support\Shell;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * One-time move from the pre-rename installation ("devkit") to nomeus. Everything on disk that
 * carried the old name: the config dir, launchd labels and the paths inside the plists, the php
 * ini and prepend file, the dashboard link, the shim. Sites keep working throughout — the client
 * package still reads the DEVKIT_* keys — and `nomeus init` moves each of them to nomeus/client.
 */
class MigrateDevkitCommand extends Command
{
    private const OLD_PREFIX = 'dev.zhuk.devkit.svc.';
    private const OLD_INI = '99-devkit.ini';
    private const OLD_QUARANTINE = '.devkit-off';

    protected $signature = 'migrate:devkit
        {--from= : the old config dir (default ~/.devkit)}
        {--dry-run : show the plan and change nothing}
        {--resume : ~/.nomeus already holds the moved data; redo the ini, agents (starting all), dashboard and shim}
        {--yes : no confirmation}';

    protected $description = 'Move a devkit-era installation to nomeus: ~/.devkit → ~/.nomeus, launchd agents, php ini, dashboard link, shim';

    public function handle(
        NomeusConfig $config, ServiceManager $services, LaunchdManager $launchd, PrependInstaller $prepend,
        XdebugManager $xdebug, ValetBridge $valet, Shell $shell, BrewBridge $brew,
    ): int {
        $old = rtrim((string) ($this->option('from') ?: NomeusConfig::homeDir().'/.devkit'), '/');
        $new = $config->dir();
        $resume = (bool) $this->option('resume');
        if ($resume) {
            if (! is_dir("{$new}/services") && ! is_file("{$new}/config.json")) {
                $this->error("--resume: {$new} does not look like a moved installation.");

                return self::FAILURE;
            }
        } elseif (! is_dir($old)) {
            $this->line("<fg=gray>{$old} does not exist — nothing to migrate.".(is_dir("{$new}/services") ? ' (Already moved? --resume finishes the remaining steps.)' : '').'</>');

            return self::SUCCESS;
        } elseif (is_dir($new)) {
            $others = array_diff(scandir($new) ?: [], ['.', '..', 'config.json', '.DS_Store']);
            if ($others !== []) {
                $this->error("{$new} already has content (".implode(', ', $others).") — refusing. If a previous run stopped part-way after the move, use --resume.");

                return self::FAILURE;
            }
        }

        $agentsDir = dirname($launchd->plistPath('x'));
        $oldPlists = glob("{$agentsDir}/".self::OLD_PREFIX.'*.plist') ?: [];
        $oldNames = array_map(fn ($p) => substr(basename($p, '.plist'), strlen(self::OLD_PREFIX)), $oldPlists);
        $versions = $brew->installedPhp();
        $oldSite = 'devkit';
        $newSite = (string) config('nomeus.site', 'nomeus');

        $this->line('<fg=yellow>plan'.($resume ? ' (resume: steps 1–2 already done)' : '').'</>');
        $this->line("  1. stop ".count($oldNames)." devkit agent(s): ".(implode(', ', $oldNames) ?: '—'));
        $this->line("  2. move {$old} → {$new}");
        $this->line('  3. php '.implode(', ', $versions).': '.self::OLD_INI.' → '.PrependInstaller::INI.', regenerate prepend, xdebug quarantine suffix; valet restart php   (before any php-based service starts)');
        $this->line('  4. rewrite service.json paths and every plist (labels '.LaunchdManager::PREFIX.'*), then start '.($resume ? 'all' : 'what was running'));
        $this->line("  5. valet: unlink {$oldSite}, link {$newSite} → ".base_path().(in_array($oldSite, $valet->isInstalled() ? $valet->secured() : [], true) ? ', secure' : ''));
        $this->line("  6. shim: {$brew->prefix()}/bin/devkit → {$brew->prefix()}/bin/nomeus");
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        if (! $this->option('yes') && ! $this->confirm('Proceed? Sites keep working; the dashboard moves to '.$newSite.'.test.')) {
            return self::SUCCESS;
        }
        $log = fn (string $l) => $this->line("<fg=gray>  {$l}</>");

        $failedStarts = [];
        try {
            $wasRunning = [];
            if (! $resume) {
                // 1. agents
                foreach ($oldNames as $name) {
                    $label = self::OLD_PREFIX.$name;
                    $wasRunning[$name] = $shell->run(['launchctl', 'print', $launchd->domain().'/'.$label], timeout: 15)->successful();
                    $shell->run(['launchctl', 'bootout', $launchd->domain().'/'.$label], timeout: 30);
                    @unlink("{$agentsDir}/{$label}.plist");
                    $log("stopped {$label}".($wasRunning[$name] ? '' : ' (was not running)'));
                }

                // 2. move
                if (is_dir($new)) {
                    if (is_file("{$new}/config.json")) {
                        rename("{$new}/config.json", "{$old}/config.json.fresh");   // the old config is the user's; keep the fresh one for reference
                    }
                    @rmdir($new);
                }
                if (! rename($old, $new)) {
                    throw new RuntimeException("could not move {$old} to {$new}");
                }
                $log("moved {$old} → {$new}");
            }

            // 3. php ini — first: the dump server and reverb are php processes, and php-fpm reads the
            //    prepend per request, so the moved prepend path must be fixed before anything runs php.
            foreach ($versions as $v) {
                $dir = $brew->prefix()."/etc/php/{$v}/conf.d";
                @unlink("{$dir}/".self::OLD_INI);
                if (is_file("{$dir}/".XdebugManager::TAP_INI.self::OLD_QUARANTINE)) {
                    rename("{$dir}/".XdebugManager::TAP_INI.self::OLD_QUARANTINE, "{$dir}/".XdebugManager::TAP_INI.XdebugManager::QUARANTINE_SUFFIX);
                }
            }
            $r = $prepend->install();
            $log('php ini written for '.implode(', ', array_merge($r['written'], $r['unchanged'])));
            if ($versions !== []) {
                $prepend->restartAndWait($log);
            }

            // 4. instances — every plist first, then the starts, so one failure can't leave others without an agent
            foreach (glob("{$new}/services/*/service.json") ?: [] as $file) {
                $data = json_decode((string) file_get_contents($file), true);
                if (! is_array($data)) {
                    continue;
                }
                $data['dir'] = str_replace($old, $new, (string) ($data['dir'] ?? dirname($file)));
                file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            }
            $instances = $services->all();
            foreach ($instances as $i) {
                $services->refreshAgent($i);
            }
            $log(count($instances).' agent(s) rewritten');
            foreach ($instances as $i) {
                if (! $resume && ! ($wasRunning[$i->name] ?? false)) {
                    $log("{$i->name}: left stopped (was not running)");
                    continue;
                }
                try {
                    $services->start($i);
                    $log("{$i->name}: running on {$i->port}");
                } catch (RuntimeException $e) {
                    $failedStarts[] = $i->name;
                    $log("{$i->name}: did not start — ".strtok($e->getMessage(), "\n"));
                }
            }

            // 5. dashboard
            if ($valet->isInstalled()) {
                $wasSecured = in_array($oldSite, $valet->secured(), true);
                if ($valet->isLinked($oldSite)) {
                    if ($wasSecured) {
                        $log($valet->unsecure($oldSite));
                    }
                    $log($valet->unlink($oldSite));
                }
                if (! $valet->isLinked($newSite)) {
                    $log($valet->link($newSite, base_path()));
                }
                if ($wasSecured && ! in_array($newSite, $valet->secured(), true)) {
                    $log($valet->secure($newSite));
                }
            }

            // 6. shim
            @unlink($brew->prefix().'/bin/devkit');
            $shim = $brew->prefix().'/bin/nomeus';
            if (! is_link($shim) && ! is_file($shim)) {
                @symlink(base_path('bin/nomeus'), $shim);
            }
            $log("shim {$shim}");
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            $this->line('<fg=yellow>Migration stopped part-way. Nothing is deleted; re-run after fixing the cause, or inspect '.$new.'.</>');

            return self::FAILURE;
        }

        $this->info("migrated. Dashboard: https://{$newSite}.".($valet->isInstalled() ? $valet->tld() : 'test'));
        $this->line('<fg=gray>Each site keeps working (the client package still reads DEVKIT_*). Run `nomeus init` per site to switch it to nomeus/client and NOMEUS_* keys.</>');
        if ($failedStarts !== []) {
            $this->error('did not start: '.implode(', ', $failedStarts).' — nomeus services:logs <name>, then nomeus services:start <name>');
        }
        $this->call('doctor');

        return $failedStarts === [] ? self::SUCCESS : self::FAILURE;
    }
}
