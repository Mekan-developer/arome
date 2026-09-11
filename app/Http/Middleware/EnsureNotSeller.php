<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Периметр продавца — поиск товара и штрихкода, не эта панель. На маршрутах, которые
 * Policy и так закрывает 403-м (мутации, `/import/backup`), эта проверка не висит —
 * только на «голых» разделах без Gate: список карточек, права, точки, устройства,
 * журнал. Аккаунт валиден (иначе вход уже отклонён на своей вкладке логина), поэтому
 * здесь не разлогиниваем — просто возвращаем туда, где ему место, как
 * {@see EnsureSeller} делает в обратную сторону.
 */
class EnsureNotSeller
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isSeller()) {
            return redirect('/search');
        }

        return $next($request);
    }
}
