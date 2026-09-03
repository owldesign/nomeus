<?php

namespace App\Services\Mcp;

use App\Services\Doctor\DoctorAggregate;
use App\Services\Dumps\CaptureFlag;
use App\Services\Dumps\DumpStore;
use App\Services\Init\InitRunner;
use App\Services\LogSources;
use App\Services\LogTailer;
use App\Services\Php\XdebugManager;
use App\Services\Php\XdebugState;
use App\Services\PhpManager;
use App\Services\ServiceManager;
use App\Services\TaskRunner;
use App\Services\ValetBridge;
use App\Support\Manifest;
use App\Support\Shell;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use RuntimeException;

/**
 * What an agent can ask nomeus. Every handler is a thin call into an existing service; the only
 * mutations are the reversible ones (service start/stop/restart, xdebug mode, dump capture).
 */
class ToolRegistry
{
    /** .env keys whose values are driver names, never secrets — safe to show. */
    public const DRIVER_KEYS = ['DB_CONNECTION', 'SESSION_DRIVER', 'CACHE_STORE', 'QUEUE_CONNECTION', 'BROADCAST_CONNECTION', 'FILESYSTEM_DISK', 'MAIL_MAILER', 'REDIS_CLIENT', 'LOG_CHANNEL'];

    /** @var array<string, array{description:string, schema:array, handler:callable}> */
    private array $tools = [];

    public function __construct(private readonly Container $app)
    {
        $this->register();
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /** @return list<array{name:string, description:string, inputSchema:array}> */
    public function describe(): array
    {
        $out = [];
        foreach ($this->tools as $name => $t) {
            $out[] = ['name' => $name, 'description' => $t['description'], 'inputSchema' => $t['schema']];
        }

        return $out;
    }

    public function names(): array
    {
        return array_keys($this->tools);
    }

    public function call(string $name, array $args): mixed
    {
        $t = $this->tools[$name] ?? throw new InvalidArgumentException("Unknown tool: {$name}");
        foreach ($t['schema']['required'] ?? [] as $req) {
            if (! array_key_exists($req, $args)) {
                throw new InvalidArgumentException("{$name}: missing required argument [{$req}]");
            }
        }

        return ($t['handler'])($args);
    }

    private function add(string $name, string $description, array $properties, array $required, callable $handler): void
    {
        $this->tools[$name] = [
            'description' => $description,
            'schema' => ['type' => 'object', 'properties' => (object) $properties, 'required' => $required] + ($properties === [] ? ['additionalProperties' => false] : []),
            'handler' => $handler,
        ];
    }

    private function register(): void
    {
        $str = fn (string $d) => ['type' => 'string', 'description' => $d];
        $int = fn (string $d) => ['type' => 'integer', 'description' => $d];
        $bool = fn (string $d) => ['type' => 'boolean', 'description' => $d];

        // ── sites ───────────────────────────────────────────────────────────
        $this->add('list_sites', 'Every Valet site nomeus serves: name, type (parked/linked/proxy), path, php version, tls, whether it has a nomeus.yml.', [], [],
            fn () => array_map(fn ($s) => $s->toArray(), $this->app->make(ValetBridge::class)->sites()));
        $this->add('site_info', 'One site in detail, including `php artisan about` for Laravel apps (environment, versions, drivers).', ['name' => $str('site name without the tld, e.g. "shop"')], ['name'],
            function ($a) {
                $site = $this->site($a['name']);
                $info = $this->app->make(\App\Services\SiteInformation::class);
                $about = $info->about($site);

                return $site->toArray() + [
                    'about' => $about,
                    'about_error' => $about === null ? $info->lastError : null,
                    'manifest_yaml' => Manifest::exists($site->path) ? file_get_contents(Manifest::find($site->path)) : null,
                ];
            });
        $this->add('site_env_keys', 'The KEYS present in a site\'s .env (never secrets), plus the values of the driver keys only (DB_CONNECTION, SESSION_DRIVER, CACHE_STORE, QUEUE_CONNECTION, BROADCAST_CONNECTION, FILESYSTEM_DISK, MAIL_MAILER, REDIS_CLIENT, LOG_CHANNEL) — enough to see what the site runs on.', ['name' => $str('site name')], ['name'],
            function ($a) {
                $site = $this->site($a['name']);
                $file = "{$site->path}/.env";
                if (! is_file($file)) {
                    return ['env' => false, 'keys' => [], 'drivers' => []];
                }
                $env = (string) file_get_contents($file);
                preg_match_all('/^\s*([A-Z_][A-Z0-9_]*)\s*=/m', $env, $m);
                $drivers = [];
                foreach (self::DRIVER_KEYS as $key) {
                    if (preg_match('/^\s*'.$key.'\s*=\s*"?([A-Za-z0-9_.-]*)"?\s*$/m', $env, $v)) {   // driver names only; a quoted/complex value is left out
                        $drivers[$key] = $v[1];
                    }
                }

                return ['env' => true, 'keys' => array_values(array_unique($m[1])), 'drivers' => $drivers];
            });

        // ── services ────────────────────────────────────────────────────────
        $this->add('list_services', 'All service instances (postgres, mysql, redis, meilisearch, mailpit, …) with type, formula, version, port and live status.', [], [],
            fn () => array_map(fn ($i) => $i->toArray() + ['status' => $this->services()->status($i)], $this->services()->all()));
        $this->add('service_status', 'Live status of one instance plus the .env lines a Laravel app needs to use it.', ['name' => $str('instance name, e.g. "pg17"')], ['name'],
            fn ($a) => ($i = $this->instance($a['name']))->toArray() + ['status' => $this->services()->status($i), 'env' => array_map(fn ($k, $v) => "{$k}={$v}", array_keys($env = $this->services()->env($i)), $env)]);
        $this->add('service_logs', 'Tail of an instance\'s logs (service.log and the server\'s own error log).', ['name' => $str('instance name'), 'lines' => $int('how many lines (default 50)')], ['name'],
            fn ($a) => $this->services()->logTail($this->instance($a['name']), max(1, min(500, (int) ($a['lines'] ?? 50)))));
        $this->add('whats_on_port', 'Which nomeus instance (or other process) listens on a TCP port on this Mac.', ['port' => $int('port number')], ['port'],
            function ($a) {
                $port = (int) $a['port'];
                foreach ($this->services()->all() as $i) {
                    if ($i->port === $port) {
                        return ['instance' => $i->name, 'type' => $i->type, 'main_port' => true];
                    }
                    foreach ($i->options as $k => $v) {
                        if (str_ends_with((string) $k, '_port') && $v === $port) {
                            return ['instance' => $i->name, 'type' => $i->type, 'listener' => substr((string) $k, 0, -5)];
                        }
                    }
                }
                $out = trim($this->app->make(Shell::class)->run(['lsof', '-nP', "-iTCP:{$port}", '-sTCP:LISTEN'], timeout: 15)->output());

                return ['instance' => null, 'lsof' => $out !== '' ? $out : 'nothing listening'];
            });
        foreach (['start', 'stop', 'restart'] as $verb) {
            $this->add("{$verb}_service", ucfirst($verb).' a service instance (launchd agent).', ['name' => $str('instance name')], ['name'],
                function ($a) use ($verb) {
                    $i = $this->instance($a['name']);
                    $this->services()->{$verb}($i);

                    return ['ok' => true, 'name' => $i->name, 'status' => $this->services()->status($i)];
                });
        }

        // ── php ─────────────────────────────────────────────────────────────
        $this->add('php_versions', 'Installed PHP versions: which is linked (global), which sites are isolated to which, php-fpm state, brew updates.', [], [],
            fn () => array_map(fn ($v) => $v->toArray(), $this->app->make(PhpManager::class)->versions()));
        $this->add('node_versions', 'Node versions installed through fnm, the default, and which sites pin which version (.nvmrc).', [], [],
            fn () => ['fnm' => ($n = $this->app->make(\App\Services\Node\NodeManager::class))->fnmBin()] + $n->installed() + ['pins' => $n->pins($this->app->make(ValetBridge::class)->sites())]);
        $this->add('xdebug_status', 'Xdebug per PHP version (installed, mode off/on/trigger) and whether an IDE is listening on the debug port.', [], [],
            fn () => ['versions' => ($x = $this->app->make(XdebugManager::class))->status(), 'ide_listening' => $x->ideListening(), 'port' => $x->port()]);
        $this->add('set_xdebug', 'Switch Xdebug for a PHP version: off (not loaded), on (every request), trigger (only with XDEBUG_TRIGGER / the browser helper). Restarts php-fpm.',
            ['version' => $str('e.g. "8.4"'), 'mode' => ['type' => 'string', 'enum' => XdebugState::MODES]], ['version', 'mode'],
            function ($a) {
                $x = $this->app->make(XdebugManager::class);
                $changed = $x->setMode((string) $a['version'], (string) $a['mode']);
                $log = [];
                if ($changed) {
                    $x->restartAndWait(function (string $l) use (&$log) { $log[] = $l; });
                }

                return ['ok' => true, 'version' => $a['version'], 'mode' => $a['mode'], 'restarted' => $changed, 'log' => $log];
            });

        // ── logs ────────────────────────────────────────────────────────────
        $this->add('tail_log', 'Recent entries from a site\'s Laravel log (newest file), or valet\'s nginx / php-fpm log. Entries are parsed: timestamp, level, message, trace.',
            ['source' => $str('site name, or "nginx", or "fpm"'), 'lines' => $int('max entries (default 50)'), 'level' => ['type' => 'string', 'enum' => ['error', 'warning', 'info', 'debug'], 'description' => 'only this severity']], ['source'],
            function ($a) {
                $sources = $this->app->make(LogSources::class);
                $src = null;
                foreach ($sources->all() as $s) {
                    if (($a['source'] === 'nginx' && $s['kind'] === 'nginx') || ($a['source'] === 'fpm' && $s['kind'] === 'php-fpm')) {
                        $src = $s;
                        break;
                    }
                }
                $src ??= $sources->latestFor((string) $a['source']) ?? throw new RuntimeException("No log for [{$a['source']}] — a site with storage/logs, or nginx / fpm.");
                $page = $this->app->make(LogTailer::class)->read($src['path']);
                $entries = $page['entries'];
                if (! empty($a['level'])) {
                    $want = match ($a['level']) { 'error' => ['emergency', 'alert', 'critical', 'error', 'emerg', 'crit'], 'warning' => ['warning', 'warn', 'notice'], 'info' => ['info'], default => ['debug'] };
                    $entries = array_values(array_filter($entries, fn ($e) => in_array($e['level'] ?? '', $want, true)));
                }

                return ['file' => $src['path'], 'entries' => array_slice($entries, -max(1, min(500, (int) ($a['lines'] ?? 50))))];
            });

        // ── dumps ───────────────────────────────────────────────────────────
        $this->add('recent_dumps', 'Latest captured dump()/dd() output and recorded queries, jobs, views, requests, logs from the Debug page store.',
            ['kind' => ['type' => 'string', 'enum' => ['all', 'dump', 'query', 'job', 'view', 'request', 'log']], 'limit' => $int('default 20')], [],
            fn ($a) => array_map(fn ($r) => array_diff_key($r, ['html' => 1]), $this->app->make(DumpStore::class)->page($a['kind'] ?? null, null, null, max(1, min(200, (int) ($a['limit'] ?? 20))))));
        $this->add('set_capture', 'Turn dump capture on (dump()/dd() go to the Debug page) or off (they print as usual). Takes effect on the next request; nothing restarts.', ['on' => $bool('true = capture')], ['on'],
            function ($a) {
                $flag = $this->app->make(CaptureFlag::class);
                $a['on'] ? $flag->on() : $flag->off();

                return ['capture' => $flag->isOn()];
            });

        // ── health ──────────────────────────────────────────────────────────
        $this->add('doctor', 'Health of every layer (valet, php, nomeus, services, dumps, mail, retention): ok/warn/fail rows, each with the fix.',
            ['section' => ['type' => 'string', 'enum' => ['valet', 'php', 'nomeus', 'services', 'dumps', 'mail', 'retention']]], [],
            fn ($a) => $this->app->make(DoctorAggregate::class)->run($a['section'] ?? null));
        $this->add('list_tasks', 'Recent background tasks (dashboard mutations) with state.', ['limit' => $int('default 20')], [],
            fn ($a) => array_map(fn ($t) => $t->toArray(), $this->app->make(TaskRunner::class)->all(max(1, min(100, (int) ($a['limit'] ?? 20))))));
        $this->add('task_log', 'The log of one task.', ['id' => $str('task id')], ['id'],
            fn ($a) => ['task' => ($t = $this->app->make(TaskRunner::class)->find((string) $a['id'])) ? $t->toArray() : null, 'log' => $t ? $this->app->make(TaskRunner::class)->log((string) $a['id']) : null]);

        // ── setup (read-only) ───────────────────────────────────────────────
        $this->add('init_plan', 'What `nomeus init` would do for a site with a nomeus.yml — the plan only; nothing is changed.', ['name' => $str('site name')], ['name'],
            function ($a) {
                $site = $this->site($a['name']);
                if (! Manifest::exists($site->path)) {
                    throw new RuntimeException("{$site->name} has no nomeus.yml.");
                }

                return array_map(fn ($s) => $s->toArray(), $this->app->make(InitRunner::class)->plan(Manifest::load($site->path)));
            });
    }

    private function services(): ServiceManager
    {
        return $this->app->make(ServiceManager::class);
    }

    private function instance(string $name): \App\Support\ServiceInstance
    {
        return $this->services()->find($name) ?? throw new RuntimeException("No service instance [{$name}]. list_services shows them.");
    }

    private function site(string $name): \App\Support\Site
    {
        return $this->app->make(ValetBridge::class)->find($name) ?? throw new RuntimeException("No site [{$name}]. list_sites shows them.");
    }
}
