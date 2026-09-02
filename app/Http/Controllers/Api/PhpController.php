<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BrewBridge;
use App\Services\PhpManager;
use App\Services\TaskRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Reads are synchronous and filesystem-backed. use/install/update are tasks: `brew install`
 * runs for minutes and `valet use` restarts the fpm serving this very request.
 */
class PhpController extends Controller
{
    public function __construct(
        private readonly PhpManager $php,
        private readonly BrewBridge $brew,
        private readonly TaskRunner $tasks,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('fresh')) {
            $this->brew->outdatedPhp(fresh: true);
        }

        return response()->json([
            'data' => [
                'global' => $this->brew->linkedPhp(),
                'installed' => array_map(fn ($v) => $v->toArray(), $this->php->versions()),
                'installable' => $this->php->installable(),
                'min_php' => $this->php->minPhp(),
            ],
        ]);
    }

    public function use(string $version): JsonResponse
    {
        return $this->enqueue(fn () => $this->php->usePlan($version));
    }

    public function install(string $version): JsonResponse
    {
        return $this->enqueue(fn () => $this->php->installPlan($version));
    }

    public function update(string $version): JsonResponse
    {
        return $this->enqueue(fn () => $this->php->updatePlan($version));
    }

    private function enqueue(callable $plan): JsonResponse
    {
        try {
            $task = $this->tasks->spawn($plan());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['task' => $task->toArray()], 202);
    }
}
