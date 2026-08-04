<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Блокировка действует и на уже открытую вкладку: признак `is_active` проверяется на
 * каждом запросе, а не только в момент входа. Иначе заблокированный администратор
 * продолжает работать в панели до тех пор, пока сам не выйдет.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['login' => 'blocked']);
        }

        return $next($request);
    }
}
