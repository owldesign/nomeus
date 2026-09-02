<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteResource;
use App\Services\SiteInformation;
use App\Services\TaskRunner;
use App\Services\ValetBridge;
use App\Support\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Reads are synchronous. Every mutation is validated here, then handed to TaskRunner and
 * answered with 202 + the task: Valet restarts nginx (and sometimes fpm) mid-command, which
 * would sever an inline response. Clients poll /api/tasks/{id} and refetch when it finishes.
 */
class SitesController extends Controller
{
    public function __construct(
        private readonly ValetBridge $valet,
        private readonly TaskRunner $tasks,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return SiteResource::collection($this->valet->sites());
    }

    public function show(string $name, SiteInformation $info): JsonResponse
    {
        $site = $this->findOrFail($name);

        return response()->json([
            'data' => $site->toArray() + ['about' => $info->about($site)],
        ]);
    }

    public function secure(string $name): JsonResponse
    {
        return $this->enqueue('secure', $this->findOrFail($name));
    }

    public function unsecure(string $name): JsonResponse
    {
        return $this->enqueue('unsecure', $this->findOrFail($name));
    }

    public function isolate(Request $request, string $name): JsonResponse
    {
        $php = (string) $request->validate(['php' => ['required', 'string', 'regex:/^(php@)?\d+\.\d+$/']])['php'];

        return $this->enqueue('isolate', $this->findOrFail($name), ['php' => $php]);
    }

    public function unisolate(string $name): JsonResponse
    {
        return $this->enqueue('unisolate', $this->findOrFail($name));
    }

    public function link(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'path' => ['required', 'string'],
        ]);

        return $this->enqueue('link', null, ['path' => $data['path']], name: $data['name']);
    }

    /** `devkit init <path>` as a task — it links, isolates, creates services and runs scripts, none of which fits in a request. */
    public function init(Request $request, string $name): JsonResponse
    {
        $site = $this->findOrFail($name);
        if ($site->type === 'proxy' || ! is_file("{$site->path}/dev.yml")) {
            return response()->json(['message' => "[{$site->name}] has no dev.yml."], 422);
        }
        $args = ['init', $site->path];
        if ($request->boolean('skip_scripts')) {
            $args[] = '--skip-scripts';
        }
        $task = $this->tasks->spawn($this->tasks->artisanPlan("init {$site->name}", $args, timeout: 3600));

        return response()->json(['task' => $task->toArray()], 202);
    }

    public function unlink(string $name): JsonResponse
    {
        $site = $this->findOrFail($name);
        if ($site->type !== 'linked') {
            return response()->json(['message' => "[{$site->name}] is {$site->type}, not a link."], 422);
        }

        return $this->enqueue('unlink', $site);
    }

    private function enqueue(string $action, ?Site $site, array $opts = [], ?string $name = null): JsonResponse
    {
        try {
            $plan = $this->valet->command($action, $name ?? $site->name, $opts);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $task = $this->tasks->spawn($plan);

        return response()->json(['task' => $task->toArray()], 202);
    }

    private function findOrFail(string $name): Site
    {
        return $this->valet->find($name) ?? abort(404, "Site [{$name}] is not parked, linked or proxied.");
    }
}
