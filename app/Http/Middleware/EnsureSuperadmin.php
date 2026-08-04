<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The console is not merely unlinked — anyone but the superadmin gets a 404, so the
 * route does not confirm its own existence.
 */
class EnsureSuperadmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isSuperadmin(), 404);

        return $next($request);
    }
}
