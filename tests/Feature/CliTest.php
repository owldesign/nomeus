<?php

use App\Support\Probe;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeBrew;
use Tests\Support\FakeValet;

beforeEach(function () {
    $this->valetFs = new FakeValet;
    $this->brewFs = (new FakeBrew)->installed('8.3', '8.3.26')->installed('8.4', '8.4.25')->linked('8.4');
    file_put_contents($this->valetFs->root.'/nomeus.json', json_encode([
        'brew_prefix' => $this->brewFs->root, 'ide' => 'phpstorm', 'db_client' => 'tableplus', 'code_dir' => '~/Sites',
    ]));
    config()->set('nomeus.config_path', $this->valetFs->root.'/nomeus.json');
    config()->set('nomeus.valet_config_dir', $this->valetFs->configDir);
    config()->set('nomeus.valet_bin', $this->valetFs->valetBin());

    // realpath: ValetBridge resolves parked dirs, and macOS's temp dir is a symlink (/var → /private/var)
    $this->alpha = realpath($this->valetFs->parked('alpha', laravel: true));
    $this->beta = realpath($this->valetFs->parked('beta', laravel: true));
    $this->valetFs->isolated('beta', '8.3');
    $this->valetFs->proxied('grafana', 'http://127.0.0.1:3000');

    $this->mock(Probe::class, function ($m) {
        $m->shouldReceive('tcp')->andReturn(false);
        $m->shouldReceive('unix')->andReturn(false);
    });
    Process::fake(['*' => Process::result('')]);
});

afterEach(function () {
    $this->valetFs->destroy();
    $this->brewFs->destroy();
});

// ── ini ─────────────────────────────────────────────────────────────────────

it('prints the ini for the global version, an isolated site, or an explicit version', function () {
    $etc = $this->brewFs->root.'/etc/php';

    $this->artisan('ini --print')->expectsOutput("$etc/8.4/php.ini")->assertSuccessful();          // cwd is not a site → global
    $this->artisan('ini --site=beta --print')->expectsOutput("$etc/8.3/php.ini")->assertSuccessful();
    $this->artisan('ini 8.3 --print')->expectsOutput("$etc/8.3/php.ini")->assertSuccessful();
    $this->artisan('ini php@8.4 --print')->expectsOutput("$etc/8.4/php.ini")->assertSuccessful();
    $this->artisan('ini 7.4 --print')->expectsOutputToContain('not installed')->assertFailed();
    $this->artisan('ini --site=nope --print')->expectsOutputToContain('not parked or linked')->assertFailed();
    $this->artisan('ini --fpm --print')->expectsOutputToContain('Not found')->assertFailed();          // valet hasn't written the pool yet
});

it('opens the ini in the configured ide', function () {
    $this->artisan('ini')->expectsOutputToContain('opened')->assertSuccessful();

    Process::assertRan(fn ($p) => $p->command === ['open', 'phpstorm://open?file='.rawurlencode($this->brewFs->root.'/etc/php/8.4/php.ini')]);
});

// ── edit ────────────────────────────────────────────────────────────────────

it('opens a site directory in the ide and refuses proxies', function () {
    $this->artisan('edit alpha')->expectsOutputToContain('opened')->assertSuccessful();
    Process::assertRan(fn ($p) => $p->command === ['open', '-a', 'PhpStorm', $this->alpha]);

    $this->artisan('edit grafana')->expectsOutputToContain('proxy')->assertFailed();
    $this->artisan('edit nope')->expectsOutputToContain('not parked or linked')->assertFailed();
    $this->artisan('edit')->expectsOutputToContain('not inside a Valet site')->assertFailed();
});

// ── db ──────────────────────────────────────────────────────────────────────

it('opens the site database from its .env in the configured client', function () {
    file_put_contents("{$this->alpha}/.env", "APP_NAME=alpha\nDB_CONNECTION=mysql\nDB_DATABASE=alpha_db\nDB_USERNAME=app\nDB_PASSWORD=\"s3cret\"\n");

    $this->artisan('db alpha --print')->expectsOutput('mysql://app:•••••@127.0.0.1:3306/alpha_db')->assertSuccessful();
    $this->artisan('db alpha')->expectsOutputToContain('→ TablePlus')->assertSuccessful();
    Process::assertRan(fn ($p) => $p->command === ['open', '-a', 'TablePlus', 'mysql://app:s3cret@127.0.0.1:3306/alpha_db?name=alpha&statusColor=ffc83d']);
});

it('handles sqlite files, missing env and proxies', function () {
    file_put_contents("{$this->beta}/.env", "DB_CONNECTION=sqlite\n");
    $this->artisan('db beta --print')->expectsOutput("{$this->beta}/database/database.sqlite")->assertSuccessful();
    $this->artisan('db beta')->expectsOutputToContain('SQLite file not found')->assertFailed();

    mkdir("{$this->beta}/database");
    touch("{$this->beta}/database/database.sqlite");
    $this->artisan('db beta')->assertSuccessful();
    Process::assertRan(fn ($p) => $p->command === ['open', '-a', 'TablePlus', "{$this->beta}/database/database.sqlite"]);

    $this->artisan('db alpha')->expectsOutputToContain('No .env')->assertFailed();
    $this->artisan('db grafana')->expectsOutputToContain('proxy')->assertFailed();
});

// ── config ──────────────────────────────────────────────────────────────────

it('reads and writes config.json with json coercion', function () {
    $this->artisan('config:get ide')->expectsOutput('phpstorm')->assertSuccessful();
    $this->artisan('config:get')->expectsOutputToContain('"db_client": "tableplus"')->assertSuccessful();
    $this->artisan('config:get nope')->expectsOutputToContain('not set')->assertFailed();

    $this->artisan('config:set ide vscode')->assertSuccessful();
    $this->artisan('config:set mail.smtp_port 2525')->assertSuccessful();
    $this->artisan('config:set ide emacs')->expectsOutputToContain('Unknown ide')->assertSuccessful();

    $cfg = json_decode(file_get_contents($this->valetFs->root.'/nomeus.json'), true);
    expect($cfg['ide'])->toBe('emacs')
        ->and($cfg['mail']['smtp_port'])->toBe(2525)
        ->and($cfg['brew_prefix'])->toBe($this->brewFs->root); // untouched keys survive
});
