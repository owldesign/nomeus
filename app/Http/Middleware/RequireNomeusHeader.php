<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every unsafe request must carry "X-Nomeus: 1". A custom header forces a CORS preflight for
 * any cross-origin caller, and config/cors.php answers no preflight — so a form post or a
 * DNS-rebinding page can never reach a mutation, which would otherwise run Valet as root.
 */
class RequireNomeusHeader
{
    public const HEADER = 'X-Nomeus';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && $request->header(self::HEADER) !== '1') {
            abort(403, 'Missing '.self::HEADER.' header.');
        }

        return $next($request);
    }
}
