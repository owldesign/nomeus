<?php

use App\Services\New\SiteScaffolder;
use App\Support\NomeusConfig;
use App\Support\Shell;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nomeus-scaffold-'.uniqid();
    mkdir($this->root);
    $this->scaffolder = new SiteScaffolder(new Shell(new NomeusConfig("{$this->root}/config.json")));
    $this->lines = [];
    $this->log = function (string $l) { $this->lines[] = $l; };
});

afterEach(fn () => File::deleteDirectory($this->root));

it('creates an empty directory, or keeps an existing one, when there is no starter', function () {
    $this->scaffolder->scaffold("{$this->root}/a", null, $this->log);
    expect(is_dir("{$this->root}/a"))->toBeTrue()->and($this->lines[0])->toStartWith('created');
    $this->scaffolder->scaffold("{$this->root}/a", null, $this->log);
    expect($this->lines[1])->toStartWith('using existing');
});

it('runs composer create-project in the parent directory and refuses a non-empty target', function () {
    Process::fake(['*composer*create-project*' => function ($p) {
        $dir = $p->path.'/'.end($p->command);               // what composer would leave behind: a populated directory
        mkdir($dir, 0755, true);
        file_put_contents("$dir/composer.json", '{}');

        return Process::result("Installing laravel/laravel\n");
    }]);

    $this->scaffolder->scaffold("{$this->root}/shop", 'laravel/laravel:^12', $this->log);
    Process::assertRan(fn ($p) => $p->command === ['composer', 'create-project', '--no-interaction', '--prefer-dist', '--no-progress', 'laravel/laravel:^12', 'shop'] && $p->path === $this->root);
    expect(is_dir("{$this->root}/shop"))->toBeTrue();

    expect(fn () => $this->scaffolder->scaffold("{$this->root}/shop", 'laravel/laravel', $this->log))->toThrow(RuntimeException::class, 'is not empty');
});
