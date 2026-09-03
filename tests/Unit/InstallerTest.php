<?php

/** The shell side has no PHP to test it; syntax, the served copy, and the UI library's no-tty rendering. */
it('has valid bash in every installer script', function () {
    foreach (['install/install.sh', 'install/install-linux.sh', 'install/bootstrap.sh', 'install/lib/ui.sh', 'install/sync-site.sh', 'install/linux/nomeus-helper'] as $f) {
        $result = \Illuminate\Support\Facades\Process::run(['bash', '-n', base_path($f)]);
        expect($result->successful())->toBeTrue("{$f}: ".$result->errorOutput());
    }
});

it('serves the same bootstrap from site/install as install/bootstrap.sh', function () {
    expect(file_get_contents(base_path('site/install')))->toBe(file_get_contents(base_path('install/bootstrap.sh')));
    expect(trim(file_get_contents(base_path('site/CNAME'))))->toBe('nomeus.dev');
});

it('renders steps without a tty and stops on a failing step with the hint', function () {
    $log = sys_get_temp_dir().'/nomeus-ui-'.uniqid().'.log';
    $script = 'source '.escapeshellarg(base_path('install/lib/ui.sh')).'; ui_init '.escapeshellarg($log).'; ui_banner 9.9.9; ui_done "already there" "yes";'
        .' ui_step "works" true; ui_hint "run the fix"; ui_step "breaks" sh -c "echo boom; exit 3"; echo "not reached"';
    $result = \Illuminate\Support\Facades\Process::env(['NO_COLOR' => '1'])->run(['bash', '-c', $script]);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain('nomeus 9.9.9')
        ->and($result->output())->toContain('already there')
        ->and($result->output())->toContain('[ok] works')
        ->and($result->output())->toContain('[!!] breaks')
        ->and($result->output())->toContain('boom')                  // the failing step's log tail
        ->and($result->output())->toContain('→ run the fix')
        ->and($result->output())->not->toContain('not reached');
    @unlink($log);
});
