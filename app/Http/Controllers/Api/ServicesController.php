<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceInstanceResource;
use App\Services\BrewBridge;
use App\Services\BrewServices;
use App\Services\ServiceManager;
use App\Services\Services\DriverRegistry;
use App\Services\TaskRunner;
use App\Support\ServiceInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reads are synchronous. Every mutation validates here (so mistakes are 422s, not failed tasks),
 * then runs the matching CLI command as a detached task: launchd and brew work happens outside
 * the request, and the task log is the command's own output.
 */
class ServicesController extends Controller
{
    private const NAME = 'regex:/^[a-z0-9][a-z0-9-]*$/';

    public function __construct(
        private readonly ServiceManager $services,
        private readonly DriverRegistry $drivers,
        private readonly BrewBridge $brew,
        private readonly TaskRunner $tasks,
        private readonly BrewServices $brewServices,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => array_map(
            fn (ServiceInstance $i) => (new ServiceInstanceResource($i))->toArray(request()),
            $this->services->all(),
        )]);
    }

    public function types(): JsonResponse
    {
        return response()->json(['data' => array_values(array_map(fn ($d) => [
            'type' => $d->type(),
            'label' => $d->label(),
            'default_port' => $d->defaultPort(),
            'formulae' => array_map(fn ($f) => [
                'formula' => $f,
                'installed' => $this->brew->isFormulaInstalled($f),
                'version' => $this->brew->formulaVersion($f),
            ], $d->formulae()),
        ], $this->drivers->all()))]);
    }

    /** brew services clusters devkit could take over. */
    public function adoptable(): JsonResponse
    {
        return response()->json(['data' => $this->brewServices->adoptable()]);
    }

    public function adopt(Request $request): JsonResponse
    {
        $data = $request->validate([
            'formula' => ['required', 'string', 'regex:/^[A-Za-z0-9._@-]+$/'],
            'name' => ['nullable', 'string', self::NAME],
            'port' => ['nullable', 'integer', 'between:1024,65535'],
        ]);
        if ($this->drivers->driverForFormula($data['formula']) === null) {
            return response()->json(['message' => "No devkit driver for [{$data['formula']}]."], 422);
        }
        if (! empty($data['name']) && $this->services->find($data['name']) !== null) {
            return response()->json(['message' => "Service [{$data['name']}] already exists."], 422);
        }
        $args = ['services:adopt', $data['formula']];
        if (! empty($data['name'])) {
            $args[] = '--name='.$data['name'];
        }
        if (! empty($data['port'])) {
            $args[] = '--port='.$data['port'];
        }

        return $this->enqueue("services:adopt {$data['formula']}", $args, timeout: 3600);
    }

    public function show(Request $request, string $name): JsonResponse
    {
        $i = $this->findOrFail($name);
        $lines = max(10, min(500, (int) $request->query('lines', 80)));

        return response()->json(['data' => (new ServiceInstanceResource($i, $lines))->toArray($request)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(array_keys($this->drivers->all()))],
            'version' => ['nullable', 'string', 'regex:/^[A-Za-z0-9.@_-]+$/'],
            'name' => ['nullable', 'string', self::NAME],
            'port' => ['nullable', 'integer', 'between:1024,65535'],
            'start' => ['nullable', 'boolean'],
        ]);
        if (! empty($data['name']) && $this->services->find($data['name']) !== null) {
            return response()->json(['message' => "Service [{$data['name']}] already exists."], 422);
        }
        if (! empty($data['version']) && $this->drivers->get($data['type'])->formulaFor($data['version']) === null) {
            return response()->json(['message' => "No {$data['type']} formula for version [{$data['version']}]."], 422);
        }

        $args = ['services:create', $data['type']];
        if (! empty($data['version'])) {
            $args[] = $data['version'];
        }
        if (! empty($data['name'])) {
            $args[] = '--name='.$data['name'];
        }
        if (! empty($data['port'])) {
            $args[] = '--port='.$data['port'];
        }
        if (array_key_exists('start', $data) && $data['start'] === false) {
            $args[] = '--no-start';
        }

        return $this->enqueue('services:create '.$data['type'].(! empty($data['name']) ? " ({$data['name']})" : ''), $args, timeout: 1800);
    }

    public function start(string $name): JsonResponse
    {
        return $this->lifecycle('start', $this->findOrFail($name));
    }

    public function stop(string $name): JsonResponse
    {
        return $this->lifecycle('stop', $this->findOrFail($name));
    }

    public function restart(string $name): JsonResponse
    {
        return $this->lifecycle('restart', $this->findOrFail($name));
    }

    public function clone(Request $request, string $name): JsonResponse
    {
        $source = $this->findOrFail($name);
        $data = $request->validate([
            'name' => ['required', 'string', self::NAME],
            'port' => ['nullable', 'integer', 'between:1024,65535'],
        ]);
        if ($this->services->find($data['name']) !== null) {
            return response()->json(['message' => "Service [{$data['name']}] already exists."], 422);
        }
        $args = ['services:clone', $source->name, $data['name']];
        if (! empty($data['port'])) {
            $args[] = '--port='.$data['port'];
        }

        return $this->enqueue("services:clone {$source->name} → {$data['name']}", $args, timeout: 600);
    }

    public function destroy(Request $request, string $name): JsonResponse
    {
        $i = $this->findOrFail($name);
        $args = ['services:delete', $i->name, '--force'];
        if ($request->boolean('keep_data')) {
            $args[] = '--keep-data';
        }

        return $this->enqueue("services:delete {$i->name}", $args, timeout: 120);
    }

    private function lifecycle(string $verb, ServiceInstance $i): JsonResponse
    {
        return $this->enqueue("services:{$verb} {$i->name}", ["services:{$verb}", $i->name], timeout: 120);
    }

    private function enqueue(string $label, array $args, int $timeout): JsonResponse
    {
        $task = $this->tasks->spawn($this->tasks->artisanPlan($label, $args, $timeout));

        return response()->json(['task' => $task->toArray()], 202);
    }

    private function findOrFail(string $name): ServiceInstance
    {
        return $this->services->find($name) ?? abort(404, "No service [{$name}].");
    }
}
