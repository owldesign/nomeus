<?php

namespace App\Services\Doctor;

use App\Services\AgentAuditor;
use App\Services\Php\AptPhp;
use App\Services\ValetBridge;
use App\Support\NomeusConfig;
use App\Support\Platform;
use App\Support\Shell;
use Symfony\Component\Yaml\Yaml;

/** nomeus itself: config, dirs, the dashboard site, the shim, the build, its git state. */
final class SelfDoctor implements Section
{
    public function __construct(
        private readonly NomeusConfig $config,
        private readonly ValetBridge $valet,
        private readonly Shell $shell,
        private readonly AgentAuditor $agents,
    ) {}

    public function name(): string
    {
        return 'nomeus';
    }

    public function checks(): array
    {
        $r = new Rows;
        $r->ok('version', (string) config('nomeus.version').' at '.base_path());

        $cfg = $this->config->path();
        if (! $this->config->exists()) {
            $r->fail('config', "{$cfg} missing — copy install/config.default.json there");
        } else {
            $r->expect(json_decode((string) file_get_contents($cfg), true) !== null, 'config', $cfg, "{$cfg} is not valid JSON");
        }
        foreach (['tasks', 'services', 'dumps', 'php'] as $sub) {
            $dir = $this->config->dir().'/'.$sub;
            $r->expect(is_dir($dir) ? is_writable($dir) : is_writable($this->config->dir()), "dir {$sub}", $dir, "{$dir} not writable");
        }

        $site = (string) config('nomeus.site', 'nomeus');
        $tld = $this->valet->isInstalled() ? $this->valet->tld() : 'test';
        $r->expect($this->valet->isInstalled() && $this->valet->isLinked($site), 'dashboard', "https://{$site}.{$tld}", "{$site}.{$tld} is not linked — cd ".base_path()." && valet link {$site}");
        $r->expect($this->valet->isInstalled() && in_array($site, $this->valet->secured(), true), 'dashboard tls', 'secured', "not secured — the clipboard and other browser APIs need https: nomeus secure {$site}", 'warn');

        $shim = $this->shell->which('nomeus');
        $target = $shim !== null ? (realpath($shim) ?: null) : null;   // a dangling symlink (checkout moved) is "found" by which but runs nothing
        $r->expect($shim !== null && $target !== null && is_executable($target), 'bin/nomeus',
            ($shim ?? '').($target && $target !== $shim ? " → {$target}" : ''),
            $shim === null
                ? 'not on PATH — ln -sf '.base_path('bin/nomeus').' '.$this->shell->brewPrefix().'/bin/nomeus'
                : "{$shim} points at a missing file — ln -sf ".base_path('bin/nomeus')." {$shim}",
            'warn');
        $r->expect(is_file(base_path('public/build/manifest.json')), 'build', 'public/build present', 'no build — npm run build');
        $r->expect(is_file(base_path('vendor/autoload.php')), 'vendor', 'composer deps present', 'composer install');
        $r->expect(class_exists(Yaml::class), 'symfony/yaml', 'present (nomeus.yml)', 'composer require symfony/yaml', 'warn');

        if (Platform::isLinux()) {
            $helper = AptPhp::HELPER;
            $r->expect(is_executable($helper), 'root helper', $helper, "{$helper} missing — sudo install -m 0755 install/linux/nomeus-helper {$helper}");
            $r->expect(is_file('/etc/sudoers.d/nomeus'), 'sudoers', '/etc/sudoers.d/nomeus', 'no NOPASSWD rule for the helper — install/install-linux.sh writes /etc/sudoers.d/nomeus');
            $linger = trim($this->shell->run(['loginctl', 'show-user', (string) (getenv('USER') ?: get_current_user()), '-p', 'Linger', '--value'], timeout: 10)->output());
            $r->expect($linger === 'yes', 'linger', 'user services outlive the login session', 'loginctl enable-linger '.(getenv('USER') ?: get_current_user()).' — without it services stop when you log out', 'warn');
        }

        // the dump server and xdebug watcher run *this* app: a moved checkout leaves them exec'ing a path that is gone
        $audit = $this->agents->audit();
        $stale = array_filter($audit, fn ($e) => $e['stale']);
        if ($audit !== []) {
            foreach ($stale as $e) {
                $r->fail("agent {$e['name']}", implode('; ', $e['reasons']).' — nomeus agents:rewrite');
            }
            if ($stale === []) {
                $r->ok('agents', count($audit).' nomeus-bound agent(s) run '.base_path());
            }
        }

        if (is_dir(base_path('.git'))) {
            $dirty = trim($this->shell->run(['git', 'status', '--porcelain'], base_path(), 20)->output());
            $r->expect($dirty === '', 'git', 'clean working tree', substr_count($dirty, "\n") + 1 .' changed file(s) — self-update refuses until committed or stashed', 'warn');
        }

        return $r->all();
    }
}
