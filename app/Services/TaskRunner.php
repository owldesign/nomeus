<?php

namespace App\Services;

use App\Support\DevkitConfig;
use App\Support\Shell;
use App\Support\Task;
use App\Support\TaskSpawner;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Detached background commands, recorded as ~/.devkit/tasks/<id>.json + <id>.log.
 *
 * Why not run Valet inline in the request: `valet secure` restarts nginx (severing the very
 * connection carrying our response) and `valet use` restarts the php-fpm executing us.
 * `task:run` therefore calls posix_setsid() before doing anything, so launchd's group kill
 * on a brew-services restart doesn't take the task with it.
 */
final class TaskRunner
{
    public const KEEP = 100;

    public function __construct(
        private readonly DevkitConfig $config,
        private readonly Shell $shell,
        private readonly TaskSpawner $spawner,
    ) {}

    public function dir(): string
    {
        return $this->config->dir().'/tasks';
    }

    /**
     * @param  array{label:string, argv:list<string>, cwd?:?string, timeout?:int}  $plan
     */
    public function spawn(array $plan): Task
    {
        if (! is_dir($this->dir())) {
            mkdir($this->dir(), 0755, true);
        }

        // Sortable by creation (microseconds), unique by suffix; `all()` is newest-first by filename.
        $id = now()->format('Ymd-His-u').'-'.Str::lower(Str::random(4));
        $task = Task::fromArray([
            'id' => $id,
            'label' => $plan['label'],
            'argv' => $plan['argv'],
            'cwd' => $plan['cwd'] ?? null,
            'status' => 'queued',
            'created_at' => now()->toIso8601String(),
            'timeout' => $plan['timeout'] ?? 900,
        ]);
        $this->write($task);
        touch($this->logPath($id));
        $this->prune();

        $this->spawner->spawn($this->runCommand($id));

        return $task;
    }

    /** The shell line that re-enters artisan with an explicit env — fpm's own env has no PATH or HOME. */
    public function runCommand(string $id): string
    {
        $env = [];
        foreach ($this->shell->env() as $k => $v) {
            $env[] = $k.'='.escapeshellarg($v);
        }

        return sprintf(
            'cd %s && env %s %s artisan task:run %s',
            escapeshellarg(base_path()),
            implode(' ', $env),
            escapeshellarg($this->shell->phpBin()),
            escapeshellarg($id),
        );
    }

    /** Executed inside `task:run`. Returns true on exit 0. */
    public function run(string $id): bool
    {
        $task = $this->find($id) ?? throw new RuntimeException("Unknown task [{$id}].");
        $this->write($task = Task::fromArray(['status' => 'running', 'started_at' => now()->toIso8601String()] + $task->toArray()));

        $log = fopen($this->logPath($id), 'a');
        $streamed = 0;
        $result = $this->shell->run(
            $task->argv,
            $task->cwd,
            $task->timeout,
            function (string $type, string $buffer) use ($log, &$streamed): void {
                $streamed += fwrite($log, $buffer) ?: 0;
            },
        );
        // Nothing streamed (faked process, or a runner that buffers): write the final output instead.
        if ($streamed === 0) {
            fwrite($log, $result->output());
            fwrite($log, $result->errorOutput());
        }
        fclose($log);

        $this->write(Task::fromArray([
            'status' => $result->successful() ? 'done' : 'failed',
            'exit_code' => $result->exitCode(),
            'finished_at' => now()->toIso8601String(),
        ] + $task->toArray()));

        return $result->successful();
    }

    public function find(string $id): ?Task
    {
        if (! preg_match('/^[A-Za-z0-9-]+$/', $id)) {
            return null;
        }
        $file = $this->dir().'/'.$id.'.json';
        if (! is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? Task::fromArray($data) : null;
    }

    /** @return list<Task> newest first */
    public function all(int $limit = 50): array
    {
        $files = glob($this->dir().'/*.json') ?: [];
        rsort($files);
        $tasks = [];
        foreach (array_slice($files, 0, $limit) as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $tasks[] = Task::fromArray($data);
            }
        }

        return $tasks;
    }

    public function log(string $id, int $tailBytes = 16384): string
    {
        $file = $this->logPath($id);
        if (! is_file($file)) {
            return '';
        }
        $size = filesize($file) ?: 0;
        $fh = fopen($file, 'r');
        if ($size > $tailBytes) {
            fseek($fh, -$tailBytes, SEEK_END);
        }
        $out = (string) stream_get_contents($fh);
        fclose($fh);

        return $out;
    }

    public function logPath(string $id): string
    {
        return $this->dir().'/'.$id.'.log';
    }

    private function write(Task $task): void
    {
        file_put_contents(
            $this->dir().'/'.$task->id.'.json',
            json_encode($task->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            LOCK_EX,
        );
    }

    private function prune(): void
    {
        $files = glob($this->dir().'/*.json') ?: [];
        rsort($files);
        foreach (array_slice($files, self::KEEP) as $old) {
            @unlink($old);
            @unlink(substr($old, 0, -5).'.log');
        }
    }
}
