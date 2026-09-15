<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuditService $audit,
    ) {}

    public function show(): Response
    {
        return Inertia::render('Login', [
            'defaultLogin' => '',
        ]);
    }

    /**
     * Самостоятельной регистрации нет: учётку выдаёт администратор сети. Вход один —
     * логин и пароль; куда вести и какие пункты показать, решает роль на учётке.
     */
    public function login(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->credentials();

        $user = $this->users->findByLogin($credentials['login']);

        if ($user && ! $user->is_active) {
            return back()->withErrors(['login' => 'blocked']);
        }

        if (! Auth::attempt(['login' => $credentials['login'], 'password' => $credentials['password']])) {
            return back()->withErrors(['login' => 'invalid']);
        }

        $request->session()->regenerate();

        $user = Auth::user();
        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->record($this->actor(), 'Вход в систему', '—', null, null, 'auth');

        return redirect($this->homePath($user));
    }

    /**
     * Суперадмин — в консоль, у кого есть панель — в каталог, остальным — поиск.
     */
    private function homePath(User $user): string
    {
        return match (true) {
            $user->isSuperadmin() => '/su',
            $user->usesPanel() => '/products',
            default => '/search',
        };
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
