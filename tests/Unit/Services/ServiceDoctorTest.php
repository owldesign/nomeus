<?php

use App\Services\ServiceDoctor;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeServicesWorld;

beforeEach(function () {
    $this->w = new FakeServicesWorld;
    $flag = new \App\Services\Dumps\CaptureFlag($this->w->config);
    $prepend = new \App\Services\Dumps\PrependInstaller($this->w->config, $this->w->brew, $flag, $this->w->shell);
    $this->doctor = new ServiceDoctor($this->w->manager, $this->w->launchd, $this->w->brew, $this->w->brewServices, $this->w->shell, $this->w->probe, $prepend);
});

afterEach(fn () => $this->w->destroy());

$byCheck = fn (array $checks) => collect($checks)->mapWithKeys(fn ($c) => [$c['check'].'|'.$c['level'] => $c['detail']]);

it('reports a healthy layer', function () use ($byCheck) {
    $this->w->manager->create('redis');
    $checks = $byCheck($this->doctor->checks());

    expect($checks->has('launchd|ok'))->toBeTrue()
        ->and($checks->has('agents dir|ok'))->toBeTrue()
        ->and($checks->get('dumps ini php 8.4|warn'))->toContain('missing — devkit dumps:install')
        ->and($checks->get('binary redis|ok'))->toContain('redis-server --version runs')
        ->and($checks->get('instance redis|ok'))->toContain('running on 127.0.0.1:6379');
});

it('flags a formula whose binary no longer loads', function () use ($byCheck) {
    $this->w->manager->create('redis', start: false);
    Process::fake(['*--version*' => Process::result('', "dyld[1]: Library not loaded: libjemalloc.2.dylib\n", 134)]);

    expect($byCheck($this->doctor->checks())->get('binary redis|fail'))->toContain('dyld[1]: Library not loaded')
        ->and($byCheck($this->doctor->checks())->get('binary redis|fail'))->toContain('brew reinstall redis');
});

it('flags crash loops, stale locks, port clashes and brew overlaps', function () use ($byCheck) {
    $this->w->brewCluster('redis', 'var/db/redis', ['dump.rdb' => ''], 6379);   // brew owns 6379 …
    $pg = $this->w->manager->create('postgresql', start: false);
    file_put_contents($pg->dataDir().'/postmaster.pid', "1\n");
    $this->w->manager->create('redis', start: false);                            // … so devkit's redis lands on 6380
    // fake a crash loop on postgres: loaded, no pid, last exit 1
    $this->w->loaded[] = 'dev.zhuk.devkit.svc.postgresql';
    Process::fake(['*launchctl*print*' => fn ($p) => match (true) {
        $p->command[2] === 'gui/501' => Process::result("gui/501 = {}\n"),
        str_contains($p->command[2], 'postgresql') => Process::result("state = waiting\n\tlast exit code = 1\n"),
        default => Process::result('', '', 113),
    }]);

    $checks = $byCheck($this->doctor->checks());

    expect($checks->get('instance postgresql|fail'))->toContain('crash-looping (last exit 1)')
        ->and($checks->get('instance redis|ok'))->toBe('stopped')
        ->and($checks->get('brew services|warn'))->toContain('devkit services:adopt redis');

    // stale lock warning shows once the instance is not loaded
    $this->w->loaded = [];
    Process::fake(['*launchctl*print*' => fn ($p) => $p->command[2] === 'gui/501' ? Process::result("gui/501 = {}\n") : Process::result('', '', 113)]);
    expect($byCheck($this->doctor->checks())->get('instance postgresql|warn'))->toContain('postmaster.pid');
});

it('runs the self-test round trip and cleans up', function () {
    $lines = [];
    $this->doctor->selfTest(function (string $l) use (&$lines) { $lines[] = $l; });

    expect(implode("\n", $lines))->toContain('round trip ok')
        ->and($this->w->manager->all())->toBe([]);                                    // both throwaways deleted
    Process::assertRan(fn ($p) => ($p->command[1] ?? '') === 'bootout');
});
