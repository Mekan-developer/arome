<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ModuleService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    public function __construct(private readonly ModuleService $modules) {}

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Shared props. Module state arrives on every page already resolved through its
     * dependencies, so no screen has to work that out for itself.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'modules' => fn (): array => $this->modules->effective(),
            'sections' => fn (): array => $this->modules->sections($user instanceof User ? $user : null),
            'clock' => now()->format('d.m.Y').' · '.now()->format('H:i'),
            'auth' => [
                'user' => $user instanceof User ? [
                    'name' => $user->name,
                    'login' => $user->login,
                    'role' => $user->role,
                    'initials' => $this->initials($user->name),
                    'shortName' => $this->shortName($user->name),
                    'roleTitle' => match ($user->role) {
                        'admin' => 'Администратор',
                        'superadmin' => 'Суперадмин',
                        default => 'Продавец',
                    },
                ] : null,
            ],
            'impersonating' => fn (): bool => (bool) $request->session()->get('impersonating', false),
            'flash' => [
                'toast' => fn () => $request->session()->get('toast'),
                'password' => fn () => $request->session()->get('password'),
            ],
        ];
    }

    /**
     * «Айнур Дурдыева» -> «АД»
     */
    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1).mb_substr($parts[1] ?? '', 0, 1));
    }

    /**
     * «Айнур Дурдыева» -> «Айнур Д.»
     */
    private function shortName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return isset($parts[1]) ? $parts[0].' '.mb_substr($parts[1], 0, 1).'.' : ($parts[0] ?? '');
    }
}
