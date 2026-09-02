<?php

use App\Exceptions\ValetCommandFailed;
use App\Services\ValetBridge;
use App\Support\DevkitConfig;
use App\Support\Shell;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeValet;

beforeEach(function () {
    $this->fake = new FakeValet;
    config()->set('devkit.valet_bin', $this->fake->valetBin());
    $this->bridge = new ValetBridge(new Shell(new DevkitConfig($this->fake->root.'/devkit.json')), $this->fake->configDir);
});

afterEach(fn () => $this->fake->destroy());

it('lists parked, linked and proxied sites with tls and isolation', function () {
    $this->fake->parked('alpha', laravel: true);
    $this->fake->parked('beta');
    $this->fake->parked('shadowed');                       // same name as a link → link wins
    $linkTarget = $this->fake->linked('shadowed');
    $this->fake->linked('api');
    $this->fake->secured('alpha');
    $this->fake->isolated('beta', '8.3');
    $this->fake->proxied('grafana', 'http://127.0.0.1:3000');

    $sites = collect($this->bridge->sites())->keyBy('name');

    expect($sites->keys()->all())->toBe(['alpha', 'api', 'beta', 'grafana', 'shadowed'])
        ->and($sites['alpha']->type)->toBe('parked')
        ->and($sites['alpha']->secured)->toBeTrue()
        ->and($sites['alpha']->url())->toBe('https://alpha.test')
        ->and($sites['alpha']->isLaravel())->toBeTrue()
        ->and($sites['beta']->php)->toBe('8.3')
        ->and($sites['beta']->nginxConf)->toBe($this->fake->configDir.'/Nginx/beta.test')
        ->and($sites['beta']->secured)->toBeFalse()
        ->and($sites['shadowed']->type)->toBe('linked')
        ->and($sites['shadowed']->path)->toBe($linkTarget)
        ->and($sites['grafana']->type)->toBe('proxy')
        ->and($sites['grafana']->path)->toBe('http://127.0.0.1:3000')
        ->and($sites['grafana']->php)->toBeNull();
});

it('finds a site by name with or without the tld', function () {
    $this->fake->parked('alpha');

    expect($this->bridge->find('alpha')?->name)->toBe('alpha')
        ->and($this->bridge->find('alpha.test')?->name)->toBe('alpha')
        ->and($this->bridge->find('nope'))->toBeNull();
});

it('excludes the Sites dir from parked paths and reads the version from cli/valet.php', function () {
    Process::fake();

    expect($this->bridge->paths())->toBe([$this->fake->sitesRoot])
        ->and($this->bridge->version())->toBe('4.12.0');
    Process::assertNothingRan();
});

it('runs mutations through the trusted valet binary with the right arguments', function () {
    $this->fake->parked('alpha');
    Process::fake(['*' => Process::result("ok\n")]);
    $bin = $this->fake->valetBin();

    $this->bridge->secure('alpha');
    $this->bridge->isolate('alpha', '8.3');
    $this->bridge->isolate('alpha', 'php@8.2');
    $this->bridge->unisolate('alpha');
    $this->bridge->link('extra', $this->fake->sitesRoot.'/alpha');
    $this->bridge->unlink('extra');

    $argv = fn ($p) => is_array($p->command) ? $p->command : [$p->command];
    Process::assertRan(fn ($p) => $argv($p) === [$bin, 'secure', 'alpha']);
    Process::assertRan(fn ($p) => $argv($p) === [$bin, 'isolate', 'php@8.3', '--site=alpha']);
    Process::assertRan(fn ($p) => $argv($p) === [$bin, 'isolate', 'php@8.2', '--site=alpha']);
    Process::assertRan(fn ($p) => $argv($p) === [$bin, 'unisolate', '--site=alpha']);
    Process::assertRan(fn ($p) => $argv($p) === [$bin, 'link', 'extra'] && $p->path === realpath($this->fake->sitesRoot.'/alpha'));
    Process::assertRan(fn ($p) => $argv($p) === [$bin, 'unlink', 'extra']);
});

it('surfaces valet failures with their output', function () {
    Process::fake(['*' => Process::result('', "Error: The [x] site could not be found in Valet's site list.", 1)]);

    $this->bridge->secure('x');
})->throws(ValetCommandFailed::class, "could not be found");

it('rejects unsafe names and bad php versions before touching valet', function () {
    Process::fake();

    expect(fn () => $this->bridge->secure('../etc'))->toThrow(RuntimeException::class, 'Invalid site name')
        ->and(fn () => $this->bridge->isolate('alpha', '8'))->toThrow(RuntimeException::class, 'PHP version')
        ->and(fn () => $this->bridge->link('x', '/definitely/not/here'))->toThrow(RuntimeException::class, 'Directory not found');
    Process::assertNothingRan();
});

it('plans commands for the task runner with labels, argv, cwd and timeouts', function () {
    $this->fake->parked('alpha');
    $bin = $this->fake->valetBin();

    $plan = $this->bridge->command('isolate', 'alpha', ['php' => '8.3']);
    expect($plan['label'])->toBe('valet isolate php@8.3 --site=alpha')
        ->and($plan['argv'])->toBe([$bin, 'isolate', 'php@8.3', '--site=alpha'])
        ->and($plan['cwd'])->toBeNull()
        ->and($plan['timeout'])->toBe(600);

    $link = $this->bridge->command('link', 'x', ['path' => $this->fake->sitesRoot.'/alpha']);
    expect($link['cwd'])->toBe(realpath($this->fake->sitesRoot.'/alpha'));

    expect(fn () => $this->bridge->command('explode', 'alpha'))->toThrow(RuntimeException::class, 'Unknown valet action');
});
