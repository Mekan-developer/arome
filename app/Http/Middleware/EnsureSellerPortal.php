<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Поиск товара — периметр продавца и менеджера: менеджер работает и в панели, и в
 * поиске, а от продавца его отличает только оптовая цена, которую решает матрица прав.
 * Администратора возвращаем в панель без разлогина — аккаунт валиден, просто не тот
 * раздел, как {@see EnsureNotSeller} делает в обратную сторону.
 */
class EnsureSellerPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->usesSellerPortal()) {
            return redirect('/products');
        }

        return $next($request);
    }
}
