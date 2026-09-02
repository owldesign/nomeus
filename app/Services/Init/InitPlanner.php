<?php

namespace App\Services\Init;

use App\Services\BrewBridge;
use App\Services\ServiceManager;
use App\Services\Services\DriverRegistry;
use App\Services\ValetBridge;
use App\Support\DotenvEditor;
use App\Support\Manifest;
use App\Support\ServiceInstance;
use App\Support\Shell;
use RuntimeException;

/**
 * Turns a manifest plus the machine's current state into an ordered list of steps, each either
 * runnable or skipped with a reason. Nothing here mutates; Step actions do, when executed.
 */
final class InitPlanner
{
    public function __construct(
        private readonly ValetBridge $valet,
        private readonly ServiceManager $services,
        private readonly DriverRegistry $drivers,
        private readonly BrewBridge $brew,
        private readonly Shell $shell,
    ) {}

    /** @return list<Step> */
    public function plan(Manifest $m): array
    {
        $steps = [];
        $env = [];                                  // accumulated .env keys, written by the env step
        $site = $this->valet->find($m->domain);
        $tld = $this->valet->tld();
        $scheme = $m->secure ? 'https' : 'http';
        $env['APP_URL'] = "{$scheme}://{$m->domain}.{$tld}";
        $env['APP_NAME'] = $m->name;

        // ── site ──────────────────────────────────────────────────────────────
        if ($site !== null) {
            $steps[] = Step::skip('site', "site {$m->domain}.{$tld}", "{$site->type} at {$site->path}");
        } else {
            $steps[] = Step::run('site', "link {$m->domain}.{$tld}", fn ($log) => $log($this->valet->link($m->domain, $m->path)), "valet link {$m->domain} in {$m->path}");
        }

        // ── tls ───────────────────────────────────────────────────────────────
        if ($m->secure) {
            $steps[] = $site?->secured
                ? Step::skip('secure', 'tls', 'already secured')
                : Step::run('secure', "secure {$m->domain}.{$tld}", fn ($log) => $log($this->valet->secure($m->domain)), "valet secure {$m->domain}");
        }

        // ── php ───────────────────────────────────────────────────────────────
        if ($m->php !== null) {
            if (! in_array($m->php, $this->brew->installedPhp(), true)) {
                $steps[] = Step::run('php', "php {$m->php}", function () use ($m) {
                    throw new RuntimeException("php@{$m->php} is not installed: devkit php:install {$m->php}");
                }, "requires php@{$m->php} (not installed)");
            } elseif ($site?->php === $m->php) {
                $steps[] = Step::skip('php', "php {$m->php}", 'already isolated');
            } elseif ($site === null || $site->php !== $m->php) {
                $steps[] = Step::run('php', "isolate php@{$m->php}", fn ($log) => $log($this->valet->isolate($m->domain, $m->php)), "valet isolate php@{$m->php} --site={$m->domain}");
            }
        }

        // ── node ──────────────────────────────────────────────────────────────
        if ($m->node !== null) {
            $nvmrc = "{$m->path}/.nvmrc";
            $current = is_file($nvmrc) ? trim((string) file_get_contents($nvmrc)) : null;
            $was = $current ?? 'absent';
            $steps[] = $current === $m->node
                ? Step::skip('node', "node {$m->node}", '.nvmrc already says so')
                : Step::run('node', "node {$m->node}", function ($log) use ($nvmrc, $m) {
                    file_put_contents($nvmrc, $m->node."\n");
                    $log(".nvmrc = {$m->node}");
                }, "write .nvmrc ({$was} → {$m->node}); nvm install/use is yours to run");
        }

        // ── services ──────────────────────────────────────────────────────────
        foreach ($m->services as $svc) {
            $driver = $this->drivers->get($svc['type']);
            $instance = $this->resolveInstance($svc['type'], $svc['instance']);
            $id = "service:{$svc['type']}";

            if ($instance === null) {
                $name = $svc['instance'];
                $steps[] = Step::run($id, "create {$svc['type']}".($name ? " ({$name})" : ''), function ($log) use ($svc, $name, &$env, $driver) {
                    $i = $this->services->create($svc['type'], $svc['version'], $name, null, true, $log);
                    $env += $driver->env($i);
                    $this->afterService($driver, $i, $svc, $env, $log);
                }, "services:create {$svc['type']}".($svc['version'] ? " {$svc['version']}" : '').($name ? " --name={$name}" : ''));
            } else {
                $status = $this->services->status($instance);
                $env += $driver->env($instance);
                if ($status['running']) {
                    $steps[] = Step::skip($id, "{$svc['type']} → {$instance->name}", "running on {$instance->port}");
                } else {
                    $steps[] = Step::run($id, "start {$instance->name}", function ($log) use ($instance) {
                        $this->services->start($instance);
                        $log("{$instance->name} running on {$instance->port}");
                    }, "services:start {$instance->name}");
                }
                $this->planAfterService($steps, $driver, $instance, $svc, $env);
            }
        }

        // ── mail ──────────────────────────────────────────────────────────────
        if ($m->mail) {
            $mailpit = $this->resolveInstance('mailpit', null);
            $driver = $this->drivers->get('mailpit');
            $from = ['MAIL_FROM_ADDRESS' => "hello@{$m->domain}.{$tld}", 'DEVKIT_MAIL_TAG' => \Illuminate\Support\Str::slug($m->name)];
            if ($mailpit === null) {
                $steps[] = Step::run('mail', 'create mailpit', function ($log) use (&$env, $driver, $from) {
                    $i = $this->services->create('mailpit', null, null, null, true, $log);
                    $env += $driver->env($i) + $from;
                }, 'services:create mailpit');
            } else {
                $env += $driver->env($mailpit) + $from;
                $steps[] = $this->services->status($mailpit)['running']
                    ? Step::skip('mail', "mail → {$mailpit->name}", "running on {$mailpit->port}")
                    : Step::run('mail', "start {$mailpit->name}", fn ($log) => $this->services->start($mailpit), "services:start {$mailpit->name}");
            }
        }

        // ── client package ────────────────────────────────────────────────────
        if ($m->client) {
            $pkg = base_path('packages/devkit-client');
            $vendor = "{$m->path}/vendor/zhuk/devkit-client";
            $steps[] = is_dir($vendor) || is_link($vendor)
                ? Step::skip('client', 'zhuk/devkit-client', 'already required')
                : Step::run('client', 'require zhuk/devkit-client', function ($log) use ($m, $pkg) {
                    $this->sh($m, ['composer', 'config', 'repositories.devkit', 'path', $pkg], $log, 60);
                    $this->sh($m, ['composer', 'require', '--dev', 'zhuk/devkit-client:@dev', '--no-interaction'], $log, 600);
                }, "composer config repositories.devkit path {$pkg} && composer require --dev zhuk/devkit-client:@dev");
        }

        // ── .env ──────────────────────────────────────────────────────────────
        $steps[] = Step::run('env', '.env', function ($log) use ($m, &$env) {
            $result = DotenvEditor::apply("{$m->path}/.env", $m->env + $env);   // manifest `env:` wins over computed keys
            $log(($result['created'] ? '.env created from .env.example; ' : '')
                .'set '.count($result['changed']).', added '.count($result['added'])
                .($result['changed'] || $result['added'] ? ': '.implode(' ', array_merge($result['changed'], $result['added'])) : ' — nothing to change'));
        }, 'write '.implode(' ', array_keys($m->env + ['APP_URL' => 1, 'APP_NAME' => 1])).' + service and mail keys');

        // ── post-init ─────────────────────────────────────────────────────────
        foreach ($m->postInit as $n => $cmd) {
            $steps[] = Step::run("post-init:{$n}", $cmd, fn ($log) => $this->sh($m, ['sh', '-c', $cmd], $log, 1800), "in {$m->path}, with the site's php first on PATH");
        }

        return $steps;
    }

    /** Named instance, else the first existing instance of the type, else null. */
    private function resolveInstance(string $type, ?string $name): ?ServiceInstance
    {
        if ($name !== null) {
            $i = $this->services->find($name);
            if ($i !== null && $i->type !== $type) {
                throw new RuntimeException("Instance [{$name}] is a {$i->type}, but the manifest wants a {$type}.");
            }

            return $i;
        }
        foreach ($this->services->all() as $i) {
            if ($i->type === $type) {
                return $i;
            }
        }

        return null;
    }

    private function planAfterService(array &$steps, $driver, ServiceInstance $i, array $svc, array &$env): void
    {
        $target = $svc['database'] ?? $svc['bucket'] ?? null;
        $key = $driver->databaseEnvKey();
        if ($target === null || $key === null) {
            return;
        }
        $env[$key] = $target;
        $steps[] = Step::run("db:{$i->name}", "{$i->name}: {$target}", fn ($log) => $this->afterService($driver, $i, $svc, $env, $log), "create {$target} on {$i->name} if missing");
    }

    /** Create the database/bucket (idempotent) and set its env key. */
    private function afterService($driver, ServiceInstance $i, array $svc, array &$env, callable $log): void
    {
        $target = $svc['database'] ?? $svc['bucket'] ?? null;
        $key = $driver->databaseEnvKey();
        if ($target === null || $key === null) {
            return;
        }
        $env[$key] = $target;
        $binDir = $this->brew->formulaBinDir($i->formula) ?? '';
        $plan = $driver->createDatabasePlan($i, $binDir, $target);
        if ($plan === null) {
            return;
        }
        $result = $this->shell->run($plan['argv'], $plan['cwd'], $plan['timeout']);
        $out = $result->errorOutput().$result->output();
        if ($result->successful()) {
            $log("{$plan['label']}: ok");
        } elseif (isset($plan['tolerate']) && preg_match($plan['tolerate'], $out)) {
            $log("{$plan['label']}: already exists");
        } else {
            throw new RuntimeException("{$plan['label']} failed: ".trim($out));
        }
    }

    /** Run in the site with the site's php first on PATH, streaming output. */
    private function sh(Manifest $m, array $argv, callable $log, int $timeout): void
    {
        $site = $this->valet->find($m->domain);
        $phpBin = $site?->php ? $this->brew->prefix()."/opt/php@{$site->php}/bin" : $this->brew->prefix().'/bin';
        $env = $this->shell->env();
        $env['PATH'] = $phpBin.':'.$env['PATH'];
        $result = \Illuminate\Support\Facades\Process::env($env)->path($m->path)->timeout($timeout)
            ->run($argv, fn ($type, $buf) => $log(rtrim($buf)));
        if (! $result->successful()) {
            throw new RuntimeException(implode(' ', $argv)." exited {$result->exitCode()}");
        }
    }
}
