<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BrewBridge;
use App\Services\Php\XdebugManager;
use App\Services\Php\XdebugState;
use App\Services\TaskRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Reads are synchronous; install and mode changes run the CLI as tasks (brew, and the fpm restart). */
class XdebugController extends Controller
{
    public function __construct(
        private readonly XdebugManager $xdebug,
        private readonly BrewBridge $brew,
        private readonly TaskRunner $tasks,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => [
            'versions' => $this->xdebug->status(),
            'linked' => $this->brew->linkedPhp(),
            'port' => $this->xdebug->port(),
            'ide_listening' => $this->xdebug->ideListening(),
            'watcher' => $this->xdebug->watcher(),
        ]]);
    }

    public function install(Request $request): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'string', Rule::in($this->brew->installedPhp())]]);
        $task = $this->tasks->spawn($this->tasks->artisanPlan("xdebug:install {$data['version']}", ['xdebug:install', $data['version']], timeout: 1800));

        return response()->json(['task' => $task->toArray()], 202);
    }

    public function mode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(XdebugState::MODES)],
            'version' => ['required', 'string', Rule::in(array_merge($this->brew->installedPhp(), ['all']))],
        ]);
        $args = ['xdebug:mode', $data['mode'], $data['version'] === 'all' ? '--all' : '--php='.$data['version']];
        $task = $this->tasks->spawn($this->tasks->artisanPlan("xdebug {$data['mode']} (php {$data['version']})", $args, timeout: 300));

        return response()->json(['task' => $task->toArray()], 202);
    }
}
