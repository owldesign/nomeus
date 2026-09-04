<?php

use App\Services\ServiceManager;
use App\Support\Probe;
use App\Support\TaskSpawner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeBrew;
use Tests\Support\FakeValet;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nomeus-rm-'.uniqid();
    mkdir("{$this->root}/nomeus", 0755, true);
    mkdir("{$this->root}/agents", 0755, true);
    $this->brewFs = (new FakeBrew)->formula('postgresql@17', '17.6', ['initdb', 'postgres', 'psql', 'dropdb'])->formula('redis', '8.2.1', ['redis-server'])->installed('8.4', '8.4.25')->linked('8.4');
    file_put_contents("{$this->root}/nomeus/config.json", json_encode(['brew_prefix' => $this->brewFs->root]));
    config()->set('nomeus.config_path', "{$this->root}/nomeus/config.json");
    config()->set('nomeus.launch_agents_dir', "{$this->root}/agents");
    config()->set('nomeus.uid', 501);
    $this->valetFs = new FakeValet;
    config()->set('nomeus.valet_config_dir', $this->valetFs->configDir);
    config()->set('nomeus.valet_bin', $this->valetFs->valetBin());
    $this->site = realpath($this->valetFs->parked('shop', laravel: true));
    file_put_contents("{$this->site}/nomeus.yml", "domain: shop\nservices:\n  - { type: postgresql, instance: pg17, database: shop }\n  - { type: redis }\n");
    $this->valetFs->secured('shop');
    $this->mock(Probe::class, function ($m) {
        $m->shouldReceive('tcp')->andReturn(false);
        $m->shouldReceive('unix')->andReturn(false);
    });
    $this->mock(TaskSpawner::class, fn ($m) => $m->shouldReceive('spawn'));
    Process::fake([
        '*launchctl*print-disabled*' => Process::result(''),
        '*launchctl*print*' => Process::result('', '', 113),
        "*'launchctl' 'list'*" => Process::result(''),
        '*launchctl*' => Process::result(''),
        '*--version*' => Process::result("stub 1.0\n"),
        '*initdb*' => Process::result('ok'),
        '*dropdb*' => Process::result(''),
        '*bin/valet*' => Process::result("ok\n"),
    ]);
    app(ServiceManager::class)->create('postgresql', null, 'pg17', start: false);
});

afterEach(function () {
    File::deleteDirectory($this->root);
    $this->brewFs->destroy();
    $this->valetFs->destroy();
});

it('removes a parked site: unsecure, drop the manifest database with --db, delete the directory', function () {
    $this->artisan('rm shop --db --yes')
        ->expectsOutputToContain('unsecure shop')
        ->expectsOutputToContain("rm -rf {$this->site}")
        ->expectsOutputToContain('dropdb shop on pg17')
        ->expectsOutputToContain('shop.test removed')
        ->assertSuccessful();

    expect(is_dir($this->site))->toBeFalse();
    $valet = $this->valetFs->valetBin();
    Process::assertRan(fn ($p) => $p->command === [$valet, 'unsecure', 'shop']);
    Process::assertRan(fn ($p) => str_ends_with($p->command[0], '/dropdb') && in_array('--if-exists', $p->command, true) && end($p->command) === 'shop');
    Process::assertNotRan(fn ($p) => ($p->command[1] ?? '') === 'unlink');   // parked, not linked
});

it('keeps databases without --db, can keep the directory, and refuses what it should', function () {
    $this->artisan('rm shop --yes --keep-dir')->expectsOutputToContain('databases untouched')->assertSuccessful();
    expect(is_dir($this->site))->toBeTrue();
    Process::assertNotRan(fn ($p) => str_ends_with($p->command[0] ?? '', '/dropdb'));

    $this->artisan('rm nope --yes')->expectsOutputToContain('No site [nope]')->assertFailed();
    $this->valetFs->linked('nomeus', base_path());
    $this->artisan('rm nomeus --yes')->expectsOutputToContain('nomeus itself')->assertFailed();
});
