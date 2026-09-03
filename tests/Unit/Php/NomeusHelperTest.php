<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/** The root helper's argument validation, driven through real bash with the root check and the privileged commands stubbed. */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nomeus-helper-'.uniqid();
    mkdir("{$this->root}/etc/php/8.4/cli/conf.d", 0755, true);
    mkdir("{$this->root}/etc/php/8.4/fpm/conf.d", 0755, true);
    $src = file_get_contents(base_path('install/linux/nomeus-helper'));
    $src = preg_replace('/^\[\[ "\$\{EUID\}" -eq 0 \]\].*$/m', 'true', $src);                       // not root here
    $src = preg_replace('/^#.*$/m', '', $src);                                                         // drop the header comments (they mention the commands)
    $src = preg_replace('/\b(systemctl|phpdismod|phpenmod|apt-get)\b[^;\n]*/', 'true ', $src);          // stub every privileged command
    $this->src = str_replace('/etc/php/', "{$this->root}/etc/php/", $src);
    $this->run = fn (array $args, string $input = '') => Process::input($input)->run(['bash', '-c', $this->src, 'nomeus-helper', ...$args]);
});

afterEach(fn () => File::deleteDirectory($this->root));

it('accepts the verbs nomeus uses', function () {
    $r = ($this->run)(['write-ini', '8.4', 'all', '99-nomeus.ini'], "auto_prepend_file=/x\n");
    expect($r->exitCode())->toBe(0, $r->errorOutput())
        ->and(file_get_contents("{$this->root}/etc/php/8.4/cli/conf.d/99-nomeus.ini"))->toBe("auto_prepend_file=/x\n")
        ->and(file_get_contents("{$this->root}/etc/php/8.4/fpm/conf.d/99-nomeus.ini"))->toBe("auto_prepend_file=/x\n");
    expect(($this->run)(['rm-ini', '8.4', '99-nomeus.ini'])->exitCode())->toBe(0)
        ->and(is_file("{$this->root}/etc/php/8.4/fpm/conf.d/99-nomeus.ini"))->toBeFalse();
    foreach ([['restart-fpm', '8.4'], ['apt-install', 'php8.4-redis', 'nginx', 'dnsmasq'], ['apt-upgrade', 'php8.4'], ['dismod', '8.4', 'xdebug'], ['enmod', '8.4', 'xdebug']] as $ok) {
        $r = ($this->run)($ok);
        expect($r->exitCode())->toBe(0, implode(' ', $ok).': '.$r->errorOutput());
    }
});

it('refuses anything outside the fixed shape', function () {
    foreach ([
        ['write-ini', '8.4; rm -rf /', 'all', 'x.ini'],
        ['write-ini', '8.4', 'cgi', 'x.ini'],
        ['rm-ini', '8.4', '../../etc/passwd'],
        ['rm-ini', '8.4', 'notini'],
        ['restart-fpm', 'all'],
        ['apt-install', 'curl'],
        ['apt-install', 'php8.4-redis', 'sudo'],
        ['apt-upgrade', 'nginx-common'],
        ['dismod', '8.4', 'x y'],
        ['shell', 'anything'],
        [],
    ] as $bad) {
        $r = ($this->run)($bad);
        expect($r->exitCode())->toBe(2, implode(' ', $bad).' should have been refused: '.$r->errorOutput());
    }
});
