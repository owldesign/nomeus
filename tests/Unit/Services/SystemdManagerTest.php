<?php

use App\Services\SystemdManager;
use App\Support\NomeusConfig;
use App\Support\Shell;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nomeus-systemd-'.uniqid();
    mkdir($this->root);
    $this->units = "{$this->root}/units";
    $this->m = new SystemdManager(new Shell(new NomeusConfig("{$this->root}/config.json")), $this->units);
    $this->show = "LoadState=loaded\nActiveState=active\nSubState=running\nMainPID=4242\nExecMainStatus=0\nUnitFileState=enabled\n";
    Process::fake([
        "*systemctl' '--user' 'show'*" => fn () => Process::result($this->show),
        '*systemctl*' => Process::result(''),
    ]);
});

afterEach(fn () => File::deleteDirectory($this->root));

it('renders a user unit with quoted argv, env, log redirection, and reloads the daemon', function () {
    $path = $this->m->writePlist('pg17', ['/home/linuxbrew/.linuxbrew/opt/postgresql@17/bin/postgres', '-D', '/home/v/.nomeus/services/pg17/data', '-c', 'log_line_prefix=%m "q"'], '/home/v/.nomeus/services/pg17', '/home/v/.nomeus/services/pg17/logs/service.log', ['PATH' => '/a:/b', 'LC_ALL' => 'en_US.UTF-8', 'APP_KEY' => false]);

    expect($path)->toBe("{$this->units}/nomeus-svc-pg17.service")->and($this->m->label('pg17'))->toBe('nomeus-svc-pg17')->and($this->m->domain())->toBe('user');
    $unit = file_get_contents($path);
    expect($unit)->toContain('ExecStart="/home/linuxbrew/.linuxbrew/opt/postgresql@17/bin/postgres" "-D" "/home/v/.nomeus/services/pg17/data" "-c" "log_line_prefix=%m \\"q\\""')
        ->and($unit)->toContain('WorkingDirectory=/home/v/.nomeus/services/pg17')
        ->and($unit)->toContain('StandardOutput=append:/home/v/.nomeus/services/pg17/logs/service.log')
        ->and($unit)->toContain('Environment="PATH=/a:/b"')
        ->and($unit)->toContain('Environment="LC_ALL=en_US.UTF-8"')
        ->and($unit)->not->toContain('APP_KEY')                                     // false = unset marker, never written
        ->and($unit)->toContain("Restart=always\n")
        ->and($unit)->toContain('WantedBy=default.target');
    Process::assertRan(fn ($p) => $p->command === ['systemctl', '--user', 'daemon-reload']);
});

it('maps systemctl show into the shared state shape and drives the lifecycle', function () {
    expect($this->m->state('pg17'))->toBe(['loaded' => true, 'pid' => 4242, 'state' => 'running', 'last_exit' => 0, 'disabled' => false]);

    $this->show = "LoadState=loaded\nActiveState=inactive\nSubState=dead\nMainPID=0\nExecMainStatus=1\nUnitFileState=disabled\n";
    expect($this->m->state('pg17'))->toBe(['loaded' => false, 'pid' => null, 'state' => 'dead', 'last_exit' => 1, 'disabled' => true])
        ->and($this->m->isDisabled('pg17'))->toBeTrue();

    $this->show = "LoadState=not-found\nActiveState=inactive\nSubState=dead\nMainPID=0\nExecMainStatus=\nUnitFileState=\n";
    expect($this->m->state('pg17'))->toBe(['loaded' => false, 'pid' => null, 'state' => null, 'last_exit' => null, 'disabled' => false]);

    $this->m->bootstrap('pg17');
    $this->m->enable('pg17');
    $this->m->kickstart('pg17');
    $this->m->disable('pg17');
    $this->m->bootout('pg17');
    foreach ([['start', 'nomeus-svc-pg17'], ['enable', 'nomeus-svc-pg17'], ['restart', 'nomeus-svc-pg17'], ['disable', 'nomeus-svc-pg17'], ['stop', 'nomeus-svc-pg17']] as $args) {
        Process::assertRan(fn ($p) => $p->command === ['systemctl', '--user', ...$args]);
    }

    // stop of an unknown unit is fine; any other failure is not (same key as the beforeEach fake, so it replaces it)
    Process::fake(['*systemctl*' => fn ($p) => match ($p->command[2] ?? '') {
        'stop' => Process::result('', 'Unit nomeus-svc-x.service not loaded.', 5),
        'start' => Process::result('', 'Failed to start: boom', 1),
        default => Process::result(''),
    }]);
    $this->m->bootout('x');
    expect(fn () => $this->m->bootstrap('x'))->toThrow(RuntimeException::class, 'systemctl start failed');
});
