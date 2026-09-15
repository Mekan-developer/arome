<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Периметр зала — поиск, не каталог панели. На маршрутах без своего Gate (список
 * карточек, права, точки, устройства, журнал) зал мягко уводим в /search.
 */
class EnsureNotSeller
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isFloorStaff()) {
            return redirect('/search');
        }

        return $next($request);
    }
}
