<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Dumps\CaptureFlag;
use App\Services\Dumps\DumpIngest;
use App\Services\Dumps\DumpStore;
use App\Services\Dumps\PrependInstaller;
use App\Services\ServiceManager;
use App\Support\Editor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DumpsController extends Controller
{
    public function __construct(
        private readonly DumpStore $store,
        private readonly CaptureFlag $flag,
        private readonly PrependInstaller $prepend,
        private readonly ServiceManager $services,
        private readonly Editor $editor,
    ) {}

    public function status(): JsonResponse
    {
        $instance = null;
        foreach ($this->services->all() as $i) {
            if ($i->type === 'dumps') {
                $instance = $i;
            }
        }

        return response()->json(['data' => [
            'capture' => $this->flag->isOn(),
            'instance' => $instance?->name,
            'port' => $instance?->port ?? 9912,
            'running' => $instance ? $this->services->status($instance)['running'] : false,
            'prepend' => $this->prepend->prependCurrent(),
            'ini' => $this->prepend->status(),
            'counts' => $this->store->counts(),
            'latest_request' => $this->store->latestRequestKey(),
        ]]);
    }

    /** ?kind=&request=&after=<id>&limit= — after omitted = newest rows. */
    public function index(Request $request): JsonResponse
    {
        $after = $request->query('after');
        $rows = $this->store->page(
            $request->query('kind'),
            $request->query('request'),
            $after === null ? null : (int) $after,
            max(1, min(500, (int) $request->query('limit', 200))),
        );
        foreach ($rows as &$r) {
            $r['url'] = $r['file'] ? $this->editor->fileUrl($r['file'], $r['line'] ?: null) : null;
            $r['payload'] = $r['payload'] !== null ? json_decode($r['payload'], true) : null;
        }

        return response()->json(['data' => $rows, 'counts' => $this->store->counts($request->query('request'))]);
    }

    public function requests(): JsonResponse
    {
        return response()->json(['data' => $this->store->requests()]);
    }

    public function header(): JsonResponse
    {
        return response()->json(['header' => DumpIngest::header()]);
    }

    public function capture(Request $request): JsonResponse
    {
        $request->boolean('on') ? $this->flag->on() : $this->flag->off();

        return response()->json(['capture' => $this->flag->isOn()]);
    }

    public function destroy(): JsonResponse
    {
        return response()->json(['cleared' => $this->store->clear()]);
    }
}
