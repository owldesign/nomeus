<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Node\NodeManager;
use App\Services\TaskRunner;
use App\Services\ValetBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NodeController extends Controller
{
    public function __construct(private readonly NodeManager $node, private readonly ValetBridge $valet, private readonly TaskRunner $tasks) {}

    public function index(): JsonResponse
    {
        $installed = $this->node->installed();

        return response()->json(['data' => [
            'fnm' => $this->node->fnmBin(),
            'versions' => $installed['versions'],
            'default' => $installed['default'],
            'pins' => $this->node->pins($this->valet->isInstalled() ? $this->valet->sites() : []),
        ]]);
    }

    public function install(Request $request): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'string', 'regex:/^(v?\d+(\.\d+){0,2}|lts)$/i'], 'default' => ['nullable', 'boolean']]);
        $args = ['node:install', $data['version']];
        if (! empty($data['default'])) {
            $args[] = '--default';
        }

        return response()->json(['task' => $this->tasks->spawn($this->tasks->artisanPlan("node:install {$data['version']}", $args, timeout: 900))->toArray()], 202);
    }

    public function use(Request $request): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'string', 'regex:/^(v?\d+(\.\d+){0,2}|lts)$/i'], 'site' => ['nullable', 'string'], 'default' => ['nullable', 'boolean']]);
        $args = ['node:use', $data['version']];
        if (! empty($data['site'])) {
            $args[] = '--site='.$data['site'];
        }
        if (! empty($data['default'])) {
            $args[] = '--default';
        }

        return response()->json(['task' => $this->tasks->spawn($this->tasks->artisanPlan("node:use {$data['version']}".(! empty($data['site']) ? " ({$data['site']})" : ''), $args, timeout: 900))->toArray()], 202);
    }
}
