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
     * There is no self-registration: the account is issued by the network administrator.
     * The login page has two tabs — «staff» (admin/manager) and «seller» — and a login
     * that belongs to the other tab is refused, same as a blocked account, before the
     * password is even checked: it is not a way to browse for logins by role either,
     * since which tab is «wrong» is exactly what the visitor just picked themselves.
     */
    public function login(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->credentials();
        $portal = $request->portal();

        $user = $this->users->findByLogin($credentials['login']);

        if ($user && ! $user->is_active) {
            return back()->withErrors(['login' => 'blocked']);
        }

        if ($user && ! $this->matchesPortal($user, $portal)) {
            return back()->withErrors(['login' => 'wrong_portal']);
        }

        if (! Auth::attempt(['login' => $credentials['login'], 'password' => $credentials['password']])) {
            return back()->withErrors(['login' => 'invalid']);
        }

        $request->session()->regenerate();

        $user = Auth::user();
        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->record($this->actor(), 'Вход в систему', '—', null, null, 'auth');

        return redirect($this->redirectPath($user));
    }

    private function matchesPortal(User $user, string $portal): bool
    {
        return $portal === 'seller' ? $user->isSeller() : ! $user->isSeller();
    }

    private function redirectPath(User $user): string
    {
        return match (true) {
            $user->isSuperadmin() => '/su',
            $user->isSeller() => '/search',
            default => '/products',
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
