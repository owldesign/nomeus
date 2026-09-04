<?php

use App\Services\BrewBridge;
use App\Services\Dumps\CaptureFlag;
use App\Services\Dumps\PrependInstaller;
use App\Services\Php\XdebugState;
use App\Support\NomeusConfig;
use App\Support\Shell;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeBrew;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nomeus-prepend-'.uniqid();
    mkdir("{$this->root}/nomeus", 0755, true);
    $this->brewFs = (new FakeBrew)->installed('8.3', '8.3.26')->installed('8.4', '8.4.25')->linked('8.4');
    file_put_contents("{$this->root}/nomeus/config.json", json_encode(['brew_prefix' => $this->brewFs->root, 'dumps' => ['port' => 9912]]));
    $config = new NomeusConfig("{$this->root}/nomeus/config.json");
    $shell = new Shell($config);
    $this->flag = new CaptureFlag($config);
    $this->installer = new PrependInstaller($config, new BrewBridge($shell), $this->flag, $shell, new XdebugState($config));
});

afterEach(function () {
    File::deleteDirectory($this->root);
    $this->brewFs->destroy();
});

it('generates the prepend with absolute paths and an ini per installed php version', function () {
    $r = $this->installer->install();

    expect($r['written'])->toBe(['8.3', '8.4'])
        ->and($r['unchanged'])->toBe([])
        ->and($r['prepend'])->toBe("{$this->root}/nomeus/php/prepend.php");
    $prepend = file_get_contents($r['prepend']);
    expect($prepend)->toContain("file_exists('{$this->root}/nomeus/dumps/capture')")
        ->and($prepend)->toContain("'VAR_DUMPER_SERVER'] = '127.0.0.1:9912'")
        ->and($prepend)->not->toContain('{{');
    expect(file_get_contents($this->brewFs->root.'/etc/php/8.4/conf.d/99-nomeus.ini'))->toContain("auto_prepend_file={$this->root}/nomeus/php/prepend.php")
        ->and($this->installer->status())->toBe(['8.3' => ['ini' => true, 'current' => true], '8.4' => ['ini' => true, 'current' => true]])
        ->and($this->installer->prependCurrent())->toBeTrue();

    // idempotent; a hand-edited ini counts as outdated
    expect($this->installer->install()['unchanged'])->toBe(['8.3', '8.4']);
    file_put_contents($this->brewFs->root.'/etc/php/8.3/conf.d/99-nomeus.ini', "auto_prepend_file=/elsewhere\n");
    expect($this->installer->status()['8.3'])->toBe(['ini' => true, 'current' => false])
        ->and($this->installer->install()['written'])->toBe(['8.3']);
});

it('runs the generated prepend: nothing without the flag, VarDumper server env with it', function () {
    $this->installer->install();
    $run = fn () => (function (string $file) {
        $_SERVER = array_diff_key($_SERVER, array_flip(['VAR_DUMPER_FORMAT', 'VAR_DUMPER_SERVER', 'NOMEUS_DUMP_SERVER', 'NOMEUS_REQUEST_ID']));
        include $file;

        return $_SERVER;
    })($this->installer->prependPath());

    expect($run())->not->toHaveKey('VAR_DUMPER_FORMAT');

    $this->flag->on();
    $server = $run();
    expect($server['VAR_DUMPER_FORMAT'])->toBe('server')
        ->and($server['VAR_DUMPER_SERVER'])->toBe('127.0.0.1:9912')
        ->and($server['NOMEUS_DUMP_SERVER'])->toBe('127.0.0.1:9912')
        ->and($server['NOMEUS_REQUEST_ID'])->toMatch('/^[0-9a-f]{12}$/');
    $this->flag->off();
    expect($run())->not->toHaveKey('NOMEUS_DUMP_SERVER');
    unset($_SERVER['VAR_DUMPER_FORMAT'], $_SERVER['VAR_DUMPER_SERVER'], $_SERVER['NOMEUS_DUMP_SERVER'], $_SERVER['NOMEUS_REQUEST_ID']);
});
