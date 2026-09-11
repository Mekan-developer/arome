<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Обратная сторона {@see EnsureNotSeller}: раздел поиска для продавца закрыт от
 * администратора и менеджера. Их аккаунт валиден, поэтому не разлогиниваем — просто
 * возвращаем в панель, их периметр.
 */
class EnsureSeller
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->isSeller()) {
            return redirect('/products');
        }

        return $next($request);
    }
}
