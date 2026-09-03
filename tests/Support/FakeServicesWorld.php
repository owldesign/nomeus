<?php

namespace Tests\Support;

use App\Services\BrewBridge;
use App\Services\BrewServices;
use App\Services\LaunchdManager;
use App\Services\ServiceManager;
use App\Services\Services\DriverRegistry;
use App\Services\ValetBridge;
use App\Support\NomeusConfig;
use App\Support\Probe;
use App\Support\Shell;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mockery;

/**
 * Everything the services layer touches, faked coherently:
 *  - a FakeBrew prefix (formulae, brew data dirs)
 *  - temp ~/.nomeus and ~/Library/LaunchAgents
 *  - a Probe whose answering ports launchctl bootstrap/bootout toggle (port read from the plist)
 *  - launchctl list showing brew's homebrew.mxcl.* agents from $brewLoaded
 *  - brew services stop that unloads the agent, removes its plist and its lock file
 *  - cp -a done in PHP
 */
final class FakeServicesWorld
{
    public string $root;
    public FakeBrew $brewFs;
    public array $answering = [];
    public array $loaded = [];
    /** @var array<string,int> label => pid */
    public array $brewLoaded = [];
    public Probe $probe;
    public NomeusConfig $config;
    public Shell $shell;
    public BrewBridge $brew;
    public LaunchdManager $launchd;
    public BrewServices $brewServices;
    public ServiceManager $manager;
    public FakeValet $valetFs;
    public ValetBridge $valet;

    public function __construct()
    {
        $this->root = sys_get_temp_dir().'/nomeus-world-'.uniqid();
        mkdir("{$this->root}/nomeus", 0755, true);
        mkdir("{$this->root}/agents", 0755, true);
        $this->brewFs = (new FakeBrew)
            ->formula('postgresql@17', '17.6', ['initdb', 'postgres', 'psql'])
            ->formula('redis', '8.2.1', ['redis-server'])
            ->formula('meilisearch', '1.15.2', ['meilisearch'])
            ->formula('typesense/tap/typesense-server', '29.0', ['typesense-server'])
            ->formula('seaweedfs', '3.97', ['weed'])
            ->formula('mailpit', '1.31.0', ['mailpit'])
            ->installed('8.3', '8.3.26')->installed('8.4', '8.4.25')->linked('8.4');
        file_put_contents("{$this->root}/nomeus/config.json", json_encode(['brew_prefix' => $this->brewFs->root]));
        $this->valetFs = new FakeValet;
        config()->set('nomeus.valet_bin', $this->valetFs->valetBin());

        $this->config = new NomeusConfig("{$this->root}/nomeus/config.json");
        $this->shell = new Shell($this->config);
        $this->brew = new BrewBridge($this->shell);
        $this->probe = Mockery::mock(Probe::class);
        $this->probe->shouldReceive('tcp')->andReturnUsing(fn (string $h, int $p) => in_array($p, $this->answering, true));
        $this->probe->shouldReceive('unix')->andReturn(false);
        $this->launchd = new LaunchdManager($this->shell, "{$this->root}/agents", 501);
        $registry = new DriverRegistry;
        $this->brewServices = new BrewServices($this->shell, $this->brew, $registry, $this->probe, "{$this->root}/agents");
        $this->valet = new ValetBridge($this->shell, $this->valetFs->configDir);
        $this->manager = new ServiceManager($this->config, $this->brew, $registry, $this->launchd, $this->shell, $this->probe, $this->brewServices, $this->valet, $this->brew);
        $this->manager->shutdownTimeout = 0;

        $labelOf = fn (string $t): string => substr($t, strrpos($t, '/') + 1);
        // The instance is saved before its agent is bootstrapped, so its record is the source of truth
        // for which port "starts answering" — drivers spell their port flag differently (-p, --port=, --api-port=, -s3.port=).
        $portOf = function (string $plist): int {
            $name = substr(basename($plist, '.plist'), strlen(LaunchdManager::PREFIX));

            return $this->manager->find($name)?->port ?? 0;
        };

        Process::fake([
            '*launchctl*print-disabled*' => Process::result(''),
            '*launchctl*print*' => fn ($p) => $p->command[2] === 'gui/501'
                ? Process::result("gui/501 = {\n\ttype = user login\n}\n")
                : (in_array($labelOf($p->command[2]), $this->loaded, true)
                    ? Process::result("state = running\n\tpid = 99\n")
                    : Process::result('', '', 113)),
            '*launchctl*bootstrap*' => function ($p) use ($portOf) {
                $this->answering[] = $portOf($p->command[3]);
                $this->loaded[] = basename($p->command[3], '.plist');

                return Process::result('');
            },
            '*launchctl*bootout*' => function ($p) use ($labelOf) {
                $label = $labelOf($p->command[2]);
                $this->loaded = array_values(array_diff($this->loaded, [$label]));
                if ($i = $this->manager->find(substr($label, strlen(LaunchdManager::PREFIX)))) {
                    $this->answering = array_values(array_diff($this->answering, [$i->port]));
                }

                return Process::result('');
            },
            "*'launchctl' 'list'*" => fn () => Process::result(implode("\n", array_map(
                fn ($label, $pid) => "{$pid}\t0\t{$label}", array_keys($this->brewLoaded), $this->brewLoaded,
            ))."\n"),
            '*launchctl*' => Process::result(''),
            '*cp*-a*' => function ($p) {
                $src = rtrim(preg_replace('#/\.$#', '', $p->command[2]), '/');
                $dst = rtrim($p->command[3], '/');
                File::copyDirectory($src, $dst);
                chmod($dst, fileperms($src) & 0777);

                return Process::result('');
            },
            '*brew*services*stop*' => function ($p) {
                $formula = $p->command[3];
                $label = BrewServices::PREFIX.$formula;
                unset($this->brewLoaded[$label]);
                @unlink("{$this->root}/agents/{$label}.plist");
                foreach (glob("{$this->brewFs->root}/var/*/postmaster.pid") ?: [] as $lock) {
                    @unlink($lock);
                }
                $this->answering = array_values(array_diff($this->answering, [5432, 3306, 6379]));

                return Process::result('');
            },
            "*php' '-m'*" => Process::result("[PHP Modules]\nCore\njson\nredis\n\n[Zend Modules]\n"),   // redis ext present
            '*--version*' => Process::result("stub 1.0\n"),   // driver binary pre-flight
            "*weed' 'version*" => Process::result("weed version 30GB 4.45\n"),
            "*mailpit' 'version*" => Process::result("mailpit v1.31.0\n"),
            '*initdb*' => Process::result("Success. You can now start the database server\n"),
            // init's collaborators: valet mutations, composer, post-init shells, database creation
            '*bin/valet*' => Process::result("ok\n"),
            "*'composer'*" => Process::result("ok\n"),
            "*'sh' '-c'*" => Process::result("ok\n"),
            '*createdb*' => Process::result(''),
            '*CREATE DATABASE*' => Process::result(''),
            "*'curl'*" => Process::result(''),
            '*pgrep*' => Process::result('', '', 1),
            '*which*' => Process::result(''),
            '*git*' => Process::result(''),
            '*psql*' => Process::result("DO\n"),
            '*brew*install*' => Process::result("==> Installing\n"),
        ]);
    }

    /** A brew services cluster: data dir under the fake prefix, a homebrew.mxcl plist, loaded + answering. */
    public function brewCluster(string $formula, string $relDataDir, array $files, int $port, bool $loaded = true): string
    {
        $dir = "{$this->brewFs->root}/{$relDataDir}";
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        foreach ($files as $name => $content) {
            file_put_contents("$dir/$name", $content);
        }
        $label = BrewServices::PREFIX.$formula;
        file_put_contents("{$this->root}/agents/{$label}.plist", "<plist/>");
        if ($loaded) {
            $this->brewLoaded[$label] = 864;
            $this->answering[] = $port;
        }

        return $dir;
    }

    /** A parked Laravel site that has laravel/reverb installed (vendor dir + installed.json). */
    public function reverbSite(string $name, string $version = '1.5.0'): string
    {
        $dir = $this->valetFs->parked($name, laravel: true);
        foreach (["$dir/vendor/laravel/reverb", "$dir/vendor/composer"] as $d) {
            if (! is_dir($d)) {
                mkdir($d, 0755, true);
            }
        }
        file_put_contents("$dir/vendor/composer/installed.json", json_encode(['packages' => [['name' => 'laravel/reverb', 'version' => "v{$version}"]]]));

        return realpath($dir);
    }

    public function destroy(): void
    {
        File::deleteDirectory($this->root);
        $this->brewFs->destroy();
        $this->valetFs->destroy();
    }
}
