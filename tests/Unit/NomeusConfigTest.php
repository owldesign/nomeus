<?php

use App\Support\NomeusConfig;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/nomeus-config-'.uniqid();
    $this->path = "{$this->dir}/config.json";
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('reports missing and returns defaults', function () {
    $config = new NomeusConfig($this->path);

    expect($config->exists())->toBeFalse()
        ->and($config->all())->toBe([])
        ->and($config->get('tld', 'test'))->toBe('test');
});

it('writes nested keys and reads them back', function () {
    $config = new NomeusConfig($this->path);
    $config->set('mail.smtp_port', 1025);
    $config->set('code_dir', '~/Sites');

    $fresh = new NomeusConfig($this->path);

    expect($fresh->get('mail.smtp_port'))->toBe(1025)
        ->and($fresh->codeDir())->toBe(NomeusConfig::homeDir().'/Sites')
        ->and(json_decode(file_get_contents($this->path), true))->toBe([
            'mail' => ['smtp_port' => 1025],
            'code_dir' => '~/Sites',
        ]);
});

it('expands a leading tilde only', function () {
    expect(NomeusConfig::expand('~/Sites'))->toBe(NomeusConfig::homeDir().'/Sites')
        ->and(NomeusConfig::expand('/abs/path'))->toBe('/abs/path');
});

it('rejects invalid json', function () {
    mkdir($this->dir, 0755, true);
    file_put_contents($this->path, '{not json');

    (new NomeusConfig($this->path))->all();
})->throws(RuntimeException::class);
