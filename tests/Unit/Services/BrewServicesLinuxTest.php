<?php

use App\Services\BrewServices;
use App\Support\Platform;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeServicesWorld;

beforeEach(function () {
    $this->w = new FakeServicesWorld;
    Platform::force('linux');
    $this->units = "{$this->w->root}/units";
    mkdir($this->units);
    file_put_contents("{$this->units}/homebrew.postgresql@17.service", "[Service]\n");
    mkdir($this->w->brewFs->root.'/var/postgresql@17', 0755, true);   // brew's data dir → adoptable
    Process::fake([
        "*systemctl' '--user' 'list-units'*" => Process::result("homebrew.postgresql@17.service loaded active running Homebrew generated unit for postgresql@17\nhomebrew.redis.service loaded inactive dead Homebrew generated unit for redis\n"),
        "*systemctl' '--user' 'show'*" => Process::result("MainPID=4321\n"),
    ]);
    $this->services = new BrewServices($this->w->shell, $this->w->brew, new \App\Services\Services\DriverRegistry, $this->w->probe, $this->units);
});

afterEach(function () {
    Platform::force(null);
    $this->w->destroy();
});

it('reads brew services from systemd --user units on linux', function () {
    $list = collect($this->services->list())->keyBy('formula');
    expect($list->keys()->all())->toBe(['postgresql@17', 'redis'])
        ->and($list['postgresql@17'])->toMatchArray(['label' => 'homebrew.postgresql@17', 'loaded' => true, 'pid' => 4321, 'plist' => "{$this->units}/homebrew.postgresql@17.service", 'type' => 'postgresql', 'has_data' => true])
        ->and($list['redis'])->toMatchArray(['loaded' => true, 'pid' => null, 'plist' => null, 'has_data' => false]);   // inactive but known to systemd
    expect(array_column($this->services->adoptable(), 'formula'))->toBe(['postgresql@17'])
        ->and(BrewServices::label('redis'))->toBe('homebrew.redis');
    Process::assertNotRan(fn ($p) => ($p->command[0] ?? '') === 'launchctl');
});
