<?php

use App\Support\NomeusConfig;
use App\Support\Probe;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeBrew;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/nomeus-status-'.uniqid();
    mkdir("{$this->dir}/valet/Sites", 0755, true);

    file_put_contents("{$this->dir}/valet/config.json", json_encode([
        'tld' => 'test',
        'loopback' => '127.0.0.1',
        'paths' => ["{$this->dir}/valet/Sites", '/Users/me/Sites'],
    ]));
    symlink($this->dir, "{$this->dir}/valet/Sites/nomeus");
    $this->brewFs = (new FakeBrew)->installed('8.3', '8.3.26')->installed('8.4', '8.4.25')->linked('8.4');
    file_put_contents("{$this->dir}/config.json", json_encode(['code_dir' => '~/Sites', 'brew_prefix' => $this->brewFs->root]));

    // A fake Valet install: bin symlink → package dir with cli/valet.php, as `valet install` lays it out.
    mkdir("{$this->dir}/pkg/cli", 0755, true);
    file_put_contents("{$this->dir}/pkg/valet", "#!/bin/sh\necho stub\n");
    chmod("{$this->dir}/pkg/valet", 0755);
    file_put_contents("{$this->dir}/pkg/cli/valet.php", "<?php\n\$version = '4.12.0';\n");
    mkdir("{$this->dir}/bin", 0755, true);
    symlink("{$this->dir}/pkg/valet", "{$this->dir}/bin/valet");

    config()->set('nomeus.config_path', "{$this->dir}/config.json");
    config()->set('nomeus.valet_config_dir', "{$this->dir}/valet");
    config()->set('nomeus.valet_bin', "{$this->dir}/bin/valet");

    // Never touch real sockets from the suite; nginx/mailpit liveness is asserted via pgrep fakes below.
    $this->mock(Probe::class, function ($m) {
        $m->shouldReceive('tcp')->andReturn(false);
        $m->shouldReceive('unix')->andReturn(false);
    });

    Process::fake([
        // Shell::run passes array commands; Symfony quotes each argument, so match with wildcards.
        '*valet*--version*' => Process::result("Laravel Valet 4.12.0\n"),
        '*php*-r*PHP_VERSION*' => Process::result('8.4.25'),
        '*pgrep*-x*nginx*' => Process::result("101\n"),
        '*pgrep*-x*dnsmasq*' => Process::result("102\n"),
        '*pgrep*-x*mailpit*' => Process::result('', '', 1),
        // Mixed argv forms: raw launch path and macOS's rewritten master/worker titles.
        '*pgrep*-fl*php-fpm*' => Process::result(
            "201 /opt/homebrew/opt/php@8.4/sbin/php-fpm --nodaemonize\n".
            "202 php-fpm: master process (/opt/homebrew/etc/php/8.3/php-fpm.conf)\n".
            "203 php-fpm: pool www\n"
        ),
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
    $this->brewFs->destroy();
});

it('returns the status snapshot', function () {
    $this->getJson('/api/status')
        ->assertOk()
        ->assertJsonPath('valet.installed', true)
        ->assertJsonPath('valet.version', '4.12.0')
        ->assertJsonPath('valet.bin', "{$this->dir}/bin/valet")
        ->assertJsonPath('valet.tld', 'test')
        ->assertJsonPath('valet.paths', ['/Users/me/Sites'])
        ->assertJsonPath('php.global', '8.4.25')
        ->assertJsonPath('php.installed', ['8.3', '8.4'])
        ->assertJsonPath('services.nginx', true)
        ->assertJsonPath('services.dnsmasq', true)
        ->assertJsonPath('services.php_fpm', ['8.3', '8.4'])
        ->assertJsonPath('services.mailpit', false)
        ->assertJsonPath('dashboard.url', 'http://nomeus.test')
        ->assertJsonPath('dashboard.linked', true)
        ->assertJsonPath('instances', [])
        ->assertJsonPath('nomeus.code_dir', NomeusConfig::homeDir().'/Sites');
});

it('reads the valet version from cli/valet.php without running valet', function () {
    Process::fake(['*valet*--version*' => Process::result('', 'sudo: a password is required', 1)]);

    $this->getJson('/api/status')->assertOk()->assertJsonPath('valet.version', '4.12.0');
    Process::assertNotRan(function ($process) {
        $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return str_contains($cmd, '--version');
    });
});

it('reports nginx up when the port answers even if pgrep cannot see it', function () {
    // Process::fake merges by pattern key, so override the beforeEach entries key-for-key.
    $down = Process::result('', '', 1);
    Process::fake([
        '*pgrep*-x*nginx*' => $down,
        '*pgrep*-x*dnsmasq*' => $down,
        '*pgrep*-x*mailpit*' => $down,
        '*pgrep*-fl*php-fpm*' => $down,
    ]);
    $this->mock(Probe::class, function ($m) {
        $m->shouldReceive('tcp')->andReturnUsing(fn (string $host, int $port) => $port === 80);
        $m->shouldReceive('unix')->andReturn(false);
    });

    $this->getJson('/api/status')
        ->assertOk()
        ->assertJsonPath('services.nginx', true)
        ->assertJsonPath('services.dnsmasq', false)
        ->assertJsonPath('services.php_fpm', [])
        ->assertJsonPath('services.mailpit', false);
});

it('reads php-fpm versions from valet sockets before falling back to pgrep', function () {
    touch("{$this->dir}/valet/valet.sock");
    touch("{$this->dir}/valet/valet83.sock");
    touch("{$this->dir}/valet/valet82.sock"); // present but not answering: stale
    Process::fake(['*pgrep*-fl*php-fpm*' => Process::result("999 php-fpm: pool www\n")]);
    $this->mock(Probe::class, function ($m) {
        $m->shouldReceive('tcp')->andReturn(false);
        $m->shouldReceive('unix')->andReturnUsing(fn (string $path) => ! str_ends_with($path, 'valet82.sock'));
    });

    $this->getJson('/api/status')
        ->assertOk()
        ->assertJsonPath('services.php_fpm', ['8.3', '8.4']);
});

it('includes diagnostics on request', function () {
    Process::fake(['*' => Process::result('')]); // nothing in this test should touch the real system

    $this->getJson('/api/status')->assertJsonMissingPath('diagnostics');
    $this->getJson('/api/status?diagnose=1')
        ->assertOk()
        ->assertJsonPath('diagnostics.sapi', PHP_SAPI)
        ->assertJsonStructure(['diagnostics' => ['env' => ['HOME', 'USER', 'PATH'], 'commands', 'sockets', 'ports']]);
});

it('refuses non-loopback callers', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->getJson('/api/status')
        ->assertForbidden();
});

it('serves the spa shell for client routes but not api paths', function () {
    $this->withoutVite(); // the shell must render with or without built assets

    $this->get('/sites')->assertOk()->assertSee('id="root"', false);
    $this->get('/api/nope')->assertNotFound();
});

it('runs the status command', function () {
    $this->artisan('status')
        ->expectsOutputToContain('4.12.0')
        ->expectsOutputToContain('8.4.25')
        ->assertSuccessful();

    $this->artisan('status --json')
        ->expectsOutputToContain('"php_fpm"')
        ->assertSuccessful();
});
