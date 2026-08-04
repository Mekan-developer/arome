<?php

namespace App\Http\Middleware;

use App\Services\ModuleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A section hidden by a feature flag must be unreachable by direct URL too, not merely
 * missing from the tab strip.
 */
class EnsureModuleEnabled
{
    public function __construct(private readonly ModuleService $modules) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless($this->modules->enabled($module), 404);

        return $next($request);
    }
}
