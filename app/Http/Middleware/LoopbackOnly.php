<?php

namespace App\Http\Middleware;

use App\Services\ValetBridge;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The dashboard and API run shell commands as you. Nothing off this machine may reach them. */
class LoopbackOnly
{
    public function __construct(private readonly ValetBridge $valet) {}

    public function handle(Request $request, Closure $next): Response
    {
        $allowed = ['127.0.0.1', '::1'];
        if ($this->valet->isInstalled()) {
            $allowed[] = $this->valet->loopback();
        }

        if (! in_array($request->ip(), $allowed, true)) {
            abort(403, 'devkit is local-only.');
        }

        return $next($request);
    }
}
