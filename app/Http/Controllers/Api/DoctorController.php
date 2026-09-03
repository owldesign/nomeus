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
