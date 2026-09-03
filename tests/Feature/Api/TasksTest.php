<?php

use App\Services\TaskRunner;
use App\Support\TaskSpawner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/nomeus-tasks-'.uniqid();
    mkdir($this->dir, 0755, true);
    $this->brewFs = new \Tests\Support\FakeBrew;
    file_put_contents("{$this->dir}/config.json", json_encode(['code_dir' => '~/Sites', 'brew_prefix' => $this->brewFs->root]));
    config()->set('nomeus.config_path', "{$this->dir}/config.json");

    $this->spawned = [];
    $this->mock(TaskSpawner::class, fn ($m) => $m->shouldReceive('spawn')
        ->andReturnUsing(function (string $cmd) { $this->spawned[] = $cmd; }));
});

afterEach(function () {
    File::deleteDirectory($this->dir);
    $this->brewFs->destroy();
});

it('spawns a task with an explicit environment and records it as queued', function () {
    $task = app(TaskRunner::class)->spawn(['label' => 'valet secure x', 'argv' => ['/x/valet', 'secure', 'x'], 'timeout' => 30]);

    expect($task->status)->toBe('queued')
        ->and(file_exists("{$this->dir}/tasks/{$task->id}.json"))->toBeTrue()
        ->and(file_exists("{$this->dir}/tasks/{$task->id}.log"))->toBeTrue()
        ->and($this->spawned)->toHaveCount(1)
        ->and($this->spawned[0])->toContain("env HOME=")
        ->and($this->spawned[0])->toContain("PATH=")
        ->and($this->spawned[0])->toContain("artisan task:run '{$task->id}'");
});

it('runs a queued task, streams output to its log and records the outcome', function () {
    $runner = app(TaskRunner::class);
    $task = $runner->spawn(['label' => 'valet secure x', 'argv' => ['/x/valet', 'secure', 'x']]);
    Process::fake(['*' => Process::result("The [x.test] site has been secured.\n")]);

    $this->artisan("task:run {$task->id}")->assertSuccessful();

    $done = $runner->find($task->id);
    expect($done->status)->toBe('done')
        ->and($done->exitCode)->toBe(0)
        ->and($done->startedAt)->not->toBeNull()
        ->and($done->finishedAt)->not->toBeNull()
        ->and($runner->log($task->id))->toContain('has been secured');
    Process::assertRan(fn ($p) => $p->command === ['/x/valet', 'secure', 'x']);
});

it('marks a failing task failed with its exit code', function () {
    $runner = app(TaskRunner::class);
    $task = $runner->spawn(['label' => 'valet secure x', 'argv' => ['/x/valet', 'secure', 'x']]);
    Process::fake(['*' => Process::result('', 'sudo: a password is required', 1)]);

    $this->artisan("task:run {$task->id}")->assertFailed();

    expect($runner->find($task->id)->status)->toBe('failed')
        ->and($runner->find($task->id)->exitCode)->toBe(1)
        ->and($runner->log($task->id))->toContain('sudo: a password is required');
});

it('lists and shows tasks over the api, with the log once finished', function () {
    $runner = app(TaskRunner::class);
    $a = $runner->spawn(['label' => 'a', 'argv' => ['true']]);
    $b = $runner->spawn(['label' => 'b', 'argv' => ['true']]);
    Process::fake(['*' => Process::result("hello\n")]);
    $this->artisan("task:run {$b->id}")->assertSuccessful();

    $this->getJson('/api/tasks')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $b->id);
    $this->getJson("/api/tasks/{$a->id}")->assertOk()->assertJsonPath('data.status', 'queued')->assertJsonMissingPath('data.log');
    $this->getJson("/api/tasks/{$b->id}")->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.log', "hello\n");
    $this->getJson('/api/tasks/nope')->assertNotFound();
    expect($runner->find('../../etc/passwd'))->toBeNull(); // ids are [A-Za-z0-9-] only
});

it('renders tasks and task:log commands', function () {
    $runner = app(TaskRunner::class);
    $task = $runner->spawn(['label' => 'valet secure x', 'argv' => ['true']]);
    Process::fake(['*' => Process::result("output line\n")]);
    $this->artisan("task:run {$task->id}")->assertSuccessful();

    // One substring per output line: Laravel registers a Mockery expectation per substring on doWrite,
    // and a line satisfies only the first expectation that matches it.
    $this->artisan('tasks')
        ->expectsOutputToContain('valet secure x')      // the row
        ->expectsOutputToContain('Log of one task')     // the footer
        ->assertSuccessful();
    $this->artisan("task:log {$task->id}")
        ->expectsOutputToContain('exit 0')              // header line
        ->expectsOutputToContain('output line')         // log body
        ->assertSuccessful();
    $this->artisan('task:log nope')->assertFailed();
});
