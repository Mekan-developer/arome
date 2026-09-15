<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Поиск товара и сканер. Кого пускать — {@see User::usesSearch()}. Остальных
 * мягко возвращаем в свой домашний раздел без разлогина.
 */
class EnsureSellerPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->usesSearch()) {
            return redirect($user->usesPanel() ? '/products' : '/login');
        }

        return $next($request);
    }
}
