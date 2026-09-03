<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LogSources;
use App\Services\LogTailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogsController extends Controller
{
    public function __construct(private readonly LogSources $sources, private readonly LogTailer $tailer) {}

    public function sources(): JsonResponse
    {
        return response()->json(['data' => $this->sources->all()]);
    }

    /** ?path=…&offset=N — offset omitted = the last 64 KB. Returns entries appended since, and the offset to send next. */
    public function tail(Request $request): JsonResponse
    {
        $source = $this->sources->resolve((string) $request->query('path', '')) ?? abort(404, 'Not a log nomeus serves.');
        $offset = $request->query('offset');
        $bytes = max(4096, min(LogTailer::MAX_BYTES, (int) $request->query('bytes', LogTailer::INITIAL_BYTES)));

        return response()->json(['data' => $this->tailer->read($source['path'], $offset === null ? null : (int) $offset, $bytes) + ['source' => $source]]);
    }

    public function truncate(Request $request): JsonResponse
    {
        $source = $this->sources->resolve((string) $request->query('path', '')) ?? abort(404, 'Not a log nomeus serves.');
        $this->tailer->truncate($source['path']);

        return response()->json(['cleared' => $source['path']]);
    }
}
