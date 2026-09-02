<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TaskRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TasksController extends Controller
{
    public function index(Request $request, TaskRunner $tasks): JsonResponse
    {
        $limit = max(1, min(200, (int) $request->query('limit', 50)));

        return response()->json(['data' => array_map(fn ($t) => $t->toArray(), $tasks->all($limit))]);
    }

    public function show(Request $request, string $id, TaskRunner $tasks): JsonResponse
    {
        $task = $tasks->find($id) ?? abort(404, "Unknown task [{$id}].");
        $data = $task->toArray();
        if ($request->boolean('log') || $task->finished()) {
            $data['log'] = $tasks->log($task->id);
        }

        return response()->json(['data' => $data]);
    }
}
