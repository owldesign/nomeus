<?php

namespace App\Services;

use App\Support\NomeusConfig;
use App\Support\Probe;
use App\Support\Shell;

/** One snapshot, rendered by both `nomeus status` and GET /api/status. */
final class StatusService
{
    public function __construct(
        private readonly NomeusConfig $config,
        private readonly ValetBridge $valet,
        private readonly Shell $shell,
        private readonly Probe $probe,
        private readonly BrewBridge $brew,
        private readonly PhpManager $php,
        private readonly ServiceManager $services,
    ) {}

    public function snapshot(): array
    {
        $site = (string) config('nomeus.site');
        $installed = $this->valet->isInstalled();
        $tld = $installed ? $this->valet->tld() : 'test';
        $loopback = $installed ? $this->valet->loopback() : '127.0.0.1';
        $smtpPort = (int) $this->config->get('mail.smtp_port', 1025);
        foreach ($this->services->all() as $i) {
            if ($i->type === 'mailpit') {
                $smtpPort = $i->port;   // the nomeus instance wins over the configured default
            }
        }

        return [
            'nomeus' => [
                'version' => config('nomeus.version'),
                'home' => base_path(),
                'config_path' => $this->config->path(),
                'config_exists' => $this->config->exists(),
                'code_dir' => $this->config->codeDir(),
            ],
            'valet' => [
                'installed' => $installed,
                'version' => $installed ? $this->valet->version() : null,
                'tld' => $tld,
                'loopback' => $installed ? $this->valet->loopback() : null,
                'paths' => $installed ? $this->valet->paths() : [],
                'bin' => $this->shell->valetBin(),
                'trusted' => $this->valet->isTrusted(),
            ],
            'php' => [
                'global' => $this->globalPhpVersion(),
                'installed' => $this->brew->installedPhp(),
            ],
            'services' => [
                // nginx and php-fpm rewrite their argv on macOS; answer-based checks first, pgrep as fallback.
                'nginx' => $this->probe->tcp($loopback, 80) || $this->shell->running('nginx'),
                'dnsmasq' => $this->shell->running('dnsmasq'),
                'php_fpm' => $this->php->runningFpmVersions(),
                'mailpit' => $this->shell->running('mailpit') || $this->probe->tcp('127.0.0.1', $smtpPort),
            ],
            'dashboard' => [
                'url' => "http://{$site}.{$tld}",
                'linked' => $installed && $this->valet->isLinked($site),
            ],
            // Port probe only: launchctl per instance is too slow for a 5-second poll.
            'instances' => array_map(fn ($i) => [
                'name' => $i->name,
                'type' => $i->type,
                'port' => $i->port,
                'running' => $this->probe->tcp('127.0.0.1', $i->port),
            ], $this->services->all()),
        ];
    }

    /** Raw material for `nomeus status --diagnose` / ?diagnose=1: what fpm actually sees and gets back. */
    public function diagnostics(): array
    {
        $commands = [
            'which' => ['which', 'valet', 'php', 'composer', 'pgrep', 'brew'],
            'valet --version' => [$this->shell->valetBin(), '--version'],
            'php -r PHP_VERSION' => ['php', '-r', 'echo PHP_VERSION;'],
            'pgrep -x nginx' => ['pgrep', '-x', 'nginx'],
            'pgrep -x dnsmasq' => ['pgrep', '-x', 'dnsmasq'],
            'pgrep -x mailpit' => ['pgrep', '-x', 'mailpit'],
            'pgrep -fl php-fpm' => ['pgrep', '-fl', 'php-fpm'],
        ];

        $out = [
            'sapi' => PHP_SAPI,
            'uid' => function_exists('posix_geteuid') ? posix_geteuid() : null,
            'user' => Shell::currentUser(),
            'groups' => $this->groups(),
            'sudoers' => ['valet' => is_file('/etc/sudoers.d/valet'), 'brew' => is_file('/etc/sudoers.d/brew')],
            'env' => $this->shell->env(),
            'commands' => [],
            'sockets' => [],
            'ports' => [],
        ];

        foreach ($commands as $label => $command) {
            $result = $this->shell->run($command, timeout: 30);
            $out['commands'][$label] = [
                'exit' => $result->exitCode(),
                'stdout' => trim($result->output()),
                'stderr' => trim($result->errorOutput()),
            ];
        }

        foreach ($this->php->valetSockets() as $path) {
            $out['sockets'][basename($path)] = $this->probe->unix($path);
        }

        $loopback = $this->valet->isInstalled() ? $this->valet->loopback() : '127.0.0.1';
        $out['ports'] = [
            "{$loopback}:80" => $this->probe->tcp($loopback, 80),
            "{$loopback}:443" => $this->probe->tcp($loopback, 443),
            '127.0.0.1:'.$this->config->get('mail.smtp_port', 1025) => $this->probe->tcp('127.0.0.1', (int) $this->config->get('mail.smtp_port', 1025)),
        ];

        return $out;
    }

    /** The sudoers rule Valet writes is for %admin (gid 80); fpm's worker has to be in it. */
    private function groups(): array
    {
        if (! function_exists('posix_getgroups')) {
            return [];
        }
        $out = [];
        foreach (posix_getgroups() as $gid) {
            $gr = posix_getgrgid($gid);
            $out[] = ($gr['name'] ?? '?').":{$gid}";
        }

        return $out;
    }

    private function globalPhpVersion(): ?string
    {
        $result = $this->shell->run(['php', '-r', 'echo PHP_VERSION;'], timeout: 10);

        return $result->successful() ? trim($result->output()) : null;
    }
}
