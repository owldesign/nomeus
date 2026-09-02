<?php

use Illuminate\Support\Facades\Process;
use Tests\Support\FakeValet;

beforeEach(function () {
    $this->valetFs = new FakeValet;
    config()->set('devkit.valet_config_dir', $this->valetFs->configDir);
    config()->set('devkit.valet_bin', $this->valetFs->valetBin());
    $this->site = realpath($this->valetFs->parked('smoke', laravel: true));
    mkdir("{$this->site}/storage/logs", 0755, true);
    $this->log = "{$this->site}/storage/logs/laravel.log";
    file_put_contents($this->log, "[2026-09-02 01:00:00] local.INFO: one\n[2026-09-02 01:00:01] local.ERROR: two\n");
    mkdir("{$this->valetFs->configDir}/Log", 0755, true);
    file_put_contents("{$this->valetFs->configDir}/Log/nginx-error.log", "2026/09/02 01:00:00 [error] 1#0: boom\n");
    Process::fake([]);
});

afterEach(fn () => $this->valetFs->destroy());

it('lists sources, tails with offsets, refuses foreign paths, and clears behind the header', function () {
    $this->getJson('/api/logs/sources')->assertOk()
        ->assertJsonPath('data.0.group', 'smoke')->assertJsonPath('data.0.label', 'laravel.log')->assertJsonPath('data.0.kind', 'laravel')
        ->assertJsonPath('data.1.group', 'valet')->assertJsonPath('data.1.kind', 'nginx');

    $first = $this->getJson('/api/logs/tail?path='.urlencode($this->log))->assertOk()
        ->assertJsonPath('data.entries.0.message', 'one')
        ->assertJsonPath('data.entries.1.severity', 'error')
        ->assertJsonPath('data.source.label', 'laravel.log')
        ->json('data');

    file_put_contents($this->log, "[2026-09-02 01:00:02] local.WARNING: three\n", FILE_APPEND);
    $this->getJson('/api/logs/tail?path='.urlencode($this->log).'&offset='.$first['offset'])->assertOk()
        ->assertJsonCount(1, 'data.entries')
        ->assertJsonPath('data.entries.0.message', 'three');

    $this->getJson('/api/logs/tail?path='.urlencode("{$this->site}/.env"))->assertNotFound();
    $this->getJson('/api/logs/tail?path=/etc/hosts')->assertNotFound();

    $this->deleteJson('/api/logs?path='.urlencode($this->log))->assertForbidden();
    $this->deleteJson('/api/logs?path='.urlencode($this->log), [], ['X-Devkit' => '1'])->assertOk()->assertJsonPath('cleared', $this->log);
    expect(filesize($this->log))->toBe(0);
    $this->deleteJson('/api/logs?path=/etc/hosts', [], ['X-Devkit' => '1'])->assertNotFound();
});

it('prints a site log and valet logs from the cli', function () {
    $this->artisan('logs smoke')->expectsOutputToContain('two')->assertSuccessful();
    $this->artisan('logs smoke --level=error')->expectsOutputToContain('two')->doesntExpectOutputToContain('one')->assertSuccessful();
    $this->artisan('logs --nginx')->expectsOutputToContain('boom')->assertSuccessful();
    $this->artisan('logs --fpm')->expectsOutputToContain('No php-fpm log yet')->assertFailed();
    $this->artisan('logs nope')->expectsOutputToContain('No logs for [nope]')->assertFailed();
    $this->artisan('logs smoke --file=missing.log')->expectsOutputToContain('has no missing.log')->assertFailed();
});
