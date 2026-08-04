<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Point;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\ModuleService;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserService $service,
        private readonly ModuleService $modules,
    ) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', User::class);

        $withPoints = $this->modules->enabled('points');
        $viewerId = Auth::id();

        return Inertia::render('Users/Index', [
            'staff' => fn () => $this->users->staff($withPoints)->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'login' => $user->login,
                'role' => $user->role,
                'roleTitle' => $user->role->label(),
                'isActive' => $user->is_active,
                'isSelf' => $user->id === $viewerId,
                'lastLogin' => $user->last_login_at?->format('d.m.Y H:i'),
                'device' => $user->device,
                'points' => $withPoints
                    ? $user->points->map(fn (Point $point): array => ['id' => $point->id, 'code' => $point->code, 'name' => $point->name])->values()
                    : [],
            ]),
            'points' => fn () => $withPoints ? Point::orderBy('id')->get(['id', 'code', 'name', 'address']) : [],
            'suggestedPassword' => fn (): string => $this->service->suggestPassword(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->service->create($request->payload(), $this->actor());

        return back();
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        $withPoints = $this->modules->enabled('points');
        $payload = $request->payload();

        if (! $withPoints) {
            unset($payload['points']);
        }

        $this->service->update($user, $payload, $this->actor());

        return back();
    }

    /**
     * Blocking revokes the token immediately; unblocking issues a new one.
     */
    public function toggleAccess(User $user): RedirectResponse
    {
        Gate::authorize('manageAccess', $user);

        $this->service->toggleAccess($user, $this->actor());

        return back();
    }

    public function changePassword(ChangePasswordRequest $request, User $user): RedirectResponse
    {
        Gate::authorize('changePassword', $user);

        $validated = $request->validated();

        $this->service->changePassword(
            $user,
            $validated['password'],
            (bool) ($validated['end_sessions'] ?? false),
            $this->actor(),
        );

        return back()->with('password', $validated['password']);
    }
}
