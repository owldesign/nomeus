<?php

use App\Services\LaunchdManager;
use App\Support\DevkitConfig;
use App\Support\Shell;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/devkit-launchd-'.uniqid();
    mkdir($this->dir, 0755, true);
    $this->m = new LaunchdManager(new Shell(new DevkitConfig("{$this->dir}/cfg.json")), "{$this->dir}/agents", 501);
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('writes a well-formed, escaped plist', function () {
    $path = $this->m->writePlist('pg', ['/opt/x/bin/postgres', '-D', '/data/dir with space & amp'], '/svc/pg', '/svc/pg/logs/service.log', ['PATH' => '/a:/b']);

    $xml = file_get_contents($path);
    expect($path)->toBe("{$this->dir}/agents/dev.zhuk.devkit.svc.pg.plist")
        ->and($xml)->toContain('<string>dev.zhuk.devkit.svc.pg</string>')
        ->and($xml)->toContain('<string>/data/dir with space &amp; amp</string>')
        ->and($xml)->toContain("<key>PATH</key>\n        <string>/a:/b</string>")
        ->and($xml)->toContain('<key>KeepAlive</key>')
        ->and($xml)->toContain('<string>/svc/pg/logs/service.log</string>');
    expect(simplexml_load_string($xml))->not->toBeFalse();
});

it('parses launchctl print into loaded/pid/state and reads the disabled list', function () {
    Process::fake([
        '*launchctl*print-disabled*' => Process::result("disabled services = {\n\t\"dev.zhuk.devkit.svc.pg\" => disabled\n\t\"com.apple.x\" => enabled\n}\n"),
        '*launchctl*print*' => Process::result("gui/501/dev.zhuk.devkit.svc.pg = {\n\tactive count = 1\n\tpath = /x\n\tstate = running\n\n\tpid = 4242\n\tlast exit code = 1\n}\n"),
    ]);

    expect($this->m->state('pg'))->toBe(['loaded' => true, 'pid' => 4242, 'state' => 'running', 'last_exit' => 1, 'disabled' => true])
        ->and($this->m->isDisabled('other'))->toBeFalse();
});

it('reports not loaded when launchctl print fails', function () {
    Process::fake(['*launchctl*print*' => Process::result('', 'Could not find service', 113)]);

    expect($this->m->state('pg')['loaded'])->toBeFalse()
        ->and($this->m->state('pg')['pid'])->toBeNull();
});

it('bootstraps only when not loaded, tolerates bootout of an unloaded job, and surfaces real failures', function () {
    Process::fake([
        '*launchctl*print-disabled*' => Process::result(''),
        '*launchctl*print*' => Process::result('', '', 113),
        '*launchctl*bootstrap*' => Process::result(''),
        '*launchctl*bootout*' => Process::result('', 'Boot-out failed: 3: No such process', 3),
        '*launchctl*enable*' => Process::result('', 'Could not enable service: 125: Domain does not support specified action', 125),
    ]);

    $this->m->bootstrap('pg');
    Process::assertRan(fn ($p) => $p->command === ['launchctl', 'bootstrap', 'gui/501', "{$this->dir}/agents/dev.zhuk.devkit.svc.pg.plist"]);

    $this->m->bootout('pg'); // ESRCH is fine
    expect(fn () => $this->m->enable('pg'))->toThrow(RuntimeException::class, 'launchctl enable failed (exit 125)');
});
