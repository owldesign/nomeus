<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatusController extends Controller
{
    public function show(Request $request, StatusService $status): JsonResponse
    {
        $data = $status->snapshot();
        if ($request->boolean('diagnose')) {
            $data['diagnostics'] = $status->diagnostics();
        }

        return response()->json($data);
    }
}
