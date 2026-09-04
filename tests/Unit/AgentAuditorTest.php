<?php

use App\Services\AgentAuditor;
use App\Services\LaunchdManager;
use App\Services\Php\XdebugWatcher;
use App\Services\SystemdManager;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeServicesWorld;

beforeEach(function () {
    $this->world = new FakeServicesWorld;
    config()->set('nomeus.config_path', "{$this->world->root}/nomeus/config.json");
    config()->set('nomeus.launch_agents_dir', "{$this->world->root}/agents");
    config()->set('nomeus.uid', 501);
    $this->watcher = new XdebugWatcher($this->world->launchd, $this->world->config, $this->world->shell);
    $this->auditor = new AgentAuditor($this->world->manager, $this->world->launchd, $this->watcher, $this->world->shell);
    $this->php = $this->world->shell->phpBin();
    // the dump server, created from this app (its plist carries base_path('artisan') and this php)
    $this->dumps = $this->world->manager->create('dumps', name: 'dumps', port: 9912);
    $this->plist = fn (string $name) => file_get_contents($this->world->launchd->plistPath($name));
    // the old checkout that the agent will be pointed at — exists so we can also test "differs but present"
    $this->old = "{$this->world->root}/Code/devkit";
    mkdir($this->old, 0755, true);
    touch("{$this->old}/artisan");
    $this->pointAt = function (string $name, string $artisan, ?string $cwd = null) {
        $p = $this->world->launchd->plistPath($name);
        $xml = str_replace('<string>'.base_path('artisan').'</string>', "<string>{$artisan}</string>", file_get_contents($p));
        if ($cwd !== null) {
            $xml = str_replace('<string>'.base_path().'</string>', "<string>{$cwd}</string>", $xml);
        }
        file_put_contents($p, $xml);
    };
});

afterEach(function () {
    $this->world->destroy();
});

it('reads a plist back as argv + cwd (launchd) and a unit back (systemd)', function () {
    expect($this->world->launchd->readAgent('dumps'))->toBe([
        'argv' => [$this->php, base_path('artisan'), 'dumps:serve', '--port=9912', '--no-interaction'],
        'cwd' => base_path(),
    ])->and($this->world->launchd->readAgent('nope'))->toBeNull();

    $systemd = new SystemdManager($this->world->shell, "{$this->world->root}/units");
    mkdir("{$this->world->root}/units");
    file_put_contents("{$this->world->root}/units/nomeus-svc-x.service", $systemd->unit('nomeus-svc-x', ['/usr/bin/php', '/home/me/a "b"/artisan', 'dumps:serve'], '/home/me/a "b"', '/tmp/x.log', []));
    expect($systemd->readAgent('x'))->toBe(['argv' => ['/usr/bin/php', '/home/me/a "b"/artisan', 'dumps:serve'], 'cwd' => '/home/me/a "b"'])
        ->and($systemd->readAgent('nope'))->toBeNull();
});

it('is clean when every nomeus-bound agent runs this app', function () {
    $audit = $this->auditor->audit();
    expect($audit)->toHaveCount(1)
        ->and($audit[0]['name'])->toBe('dumps')
        ->and($audit[0]['stale'])->toBeFalse()
        ->and($this->auditor->stale())->toBe([])
        ->and($this->auditor->rewrite())->toBe([]);
});

it('ignores brew-backed services and instances without a unit file', function () {
    $this->world->manager->create('redis', name: 'cache', port: 6380);
    $this->world->launchd->removePlist('dumps');
    expect($this->auditor->audit())->toBe([]);
});

it('flags the dump server that still points at the old devkit checkout, with the missing path named', function () {
    ($this->pointAt)('dumps', "{$this->old}/artisan", $this->old);
    unlink("{$this->old}/artisan");   // the checkout was renamed: nothing there anymore

    $stale = $this->auditor->stale();
    expect($stale)->toHaveCount(1)
        ->and($stale[0]['reasons'])->toContain("{$this->old}/artisan is missing")
        ->and($stale[0]['reasons'])->toContain("runs {$this->old}/artisan, this app is ".base_path('artisan'));
});

it('rewrites a stale agent to this app, re-anchors its record, and bounces it only if launchd held it', function () {
    ($this->pointAt)('dumps', "{$this->old}/artisan", $this->old);
    $this->dumps = $this->dumps->with(['options' => ['site_path' => $this->old] + $this->dumps->options]);
    $this->dumps->save();
    // launchd holds it (crash-looping or not, "loaded" is what decides the bounce)
    $this->world->loaded[] = LaunchdManager::PREFIX.'dumps';

    $lines = [];
    expect($this->auditor->rewrite(function (string $l) use (&$lines) {
        $lines[] = $l;
    }))->toBe(['dumps']);

    expect($this->world->launchd->readAgent('dumps')['argv'][1])->toBe(base_path('artisan'))
        ->and($this->world->launchd->readAgent('dumps')['cwd'])->toBe(base_path())
        ->and($this->world->manager->find('dumps')->options['site_path'])->toBe(base_path())
        ->and($this->auditor->stale())->toBe([])
        ->and(implode("\n", $lines))->toContain('dumps: rewritten → '.$this->php.' '.base_path('artisan'));
    Process::assertRan(fn ($p) => $p->command[1] === 'bootout' && str_ends_with($p->command[2], 'dev.nomeus.svc.dumps'));
    Process::assertRan(fn ($p) => $p->command[1] === 'bootstrap' && str_ends_with($p->command[3], 'dev.nomeus.svc.dumps.plist'));
});

it('leaves a stopped stale agent stopped after rewriting it', function () {
    ($this->pointAt)('dumps', "{$this->old}/artisan");
    $this->world->loaded = [];   // launchd doesn't hold it (the bootstrap fake would put it back)

    expect($this->auditor->rewrite())->toBe(['dumps'])
        ->and($this->auditor->stale())->toBe([])
        ->and($this->world->loaded)->toBe([]);
});

it('audits the xdebug watcher too and rewrites it through enable()', function () {
    $this->watcher->enable();
    expect(collect($this->auditor->audit())->pluck('name')->all())->toBe(['dumps', 'xdebug-detect']);

    ($this->pointAt)('xdebug-detect', "{$this->old}/artisan");
    $stale = $this->auditor->stale();
    expect($stale)->toHaveCount(1)->and($stale[0]['kind'])->toBe('watcher');

    expect($this->auditor->rewrite())->toBe(['xdebug-detect'])
        ->and($this->world->launchd->readAgent('xdebug-detect')['argv'])->toBe([$this->php, base_path('artisan'), 'xdebug:watch']);
});

it('names a php that was relinked away', function () {
    $p = $this->world->launchd->plistPath('dumps');
    file_put_contents($p, str_replace("<string>{$this->php}</string>", '<string>/opt/gone/bin/php</string>', file_get_contents($p)));

    $reasons = $this->auditor->stale()[0]['reasons'];
    expect($reasons)->toContain('/opt/gone/bin/php is missing')
        ->and($reasons)->toContain("php /opt/gone/bin/php, now {$this->php}");
});
