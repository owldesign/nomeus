<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Doctor\DoctorAggregate;
use App\Services\TaskRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoctorController extends Controller
{
    public function __construct(private readonly DoctorAggregate $doctor, private readonly TaskRunner $tasks) {}

    public function index(Request $request): JsonResponse
    {
        $section = $request->query('section');

        return response()->json(['data' => $this->doctor->run($section ?: null) + ['sections' => $this->doctor->sectionNames()]]);
    }

    /**
     * Run a fix the doctor proposed. Only `nomeus <command> …` lines are accepted, and only for commands
     * that exist and are reversible or idempotent — the doctor's detail strings are the source of truth.
     */
    public function fix(Request $request): JsonResponse
    {
        $data = $request->validate(['command' => ['required', 'string', 'max:300', 'regex:/^nomeus [a-z][a-z0-9:-]*( [^;&|`$<>]+)*$/']]);
        $argv = preg_split('/\s+/', trim($data['command']));
        array_shift($argv);   // "nomeus"
        $name = $argv[0] ?? '';
        if (! in_array($name, self::FIXABLE, true)) {
            return response()->json(['message' => "{$name} is not a command the dashboard runs as a fix — run it from a terminal."], 422);
        }
        $task = $this->tasks->spawn($this->tasks->artisanPlan($data['command'], $argv, timeout: 900));

        return response()->json(['task' => $task->toArray()], 202);
    }

    /** Idempotent or reversible fixes the doctor prints; anything else (rm, delete, self-update, migrate) stays in the terminal. */
    public const FIXABLE = ['dumps:install', 'xdebug:mode', 'xdebug:install', 'php:ext', 'node:install', 'services:start', 'services:restart', 'services:logs', 'services:adopt', 'services:upgrade', 'secure', 'link', 'mail', 'services:create', 'dumps:clear', 'agents:rewrite'];

    /** `nomeus self-update` as a task — the dashboard survives its own rebuild because the task is detached. */
    public function update(Request $request): JsonResponse
    {
        $args = ['self-update'];
        if ($request->boolean('check')) {
            $args[] = '--check';
        }
        if ($request->boolean('no_build')) {
            $args[] = '--no-build';
        }
        $task = $this->tasks->spawn($this->tasks->artisanPlan(in_array('--check', $args, true) ? 'self-update --check' : 'self-update', $args, timeout: 1800));

        return response()->json(['task' => $task->toArray()], 202);
    }
}
