<?php

namespace App\Services;

use App\Models\Point;
use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordService $passwords,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array{name: string, login: string, role: string, password: string, points?: list<int>}  $data
     */
    public function create(array $data, string $actor): User
    {
        return DB::transaction(function () use ($data, $actor): User {
            $user = User::create([
                'name' => $data['name'],
                'login' => $data['login'],
                'role' => $data['role'],
                'password' => Hash::make($data['password']),
                'is_active' => true,
                'device' => $data['role'] === 'admin' ? 'Веб' : null,
            ]);

            $user->points()->sync($this->allowedPointIds($data['points'] ?? []));

            $this->audit->record($actor, 'Создан сотрудник', $user->login.' · '.$user->name, null, 'активен', 'user');

            return $user;
        });
    }

    /**
     * @param  array{name: string, login: string, role: string, points?: list<int>}  $data
     */
    public function update(User $user, array $data, string $actor): User
    {
        return DB::transaction(function () use ($user, $data, $actor): User {
            $before = $user->name.' · '.$user->login.' · '.$user->role->value;

            $user->update([
                'name' => $data['name'],
                'login' => $data['login'],
                'role' => $data['role'],
            ]);

            if (array_key_exists('points', $data)) {
                $user->points()->sync($this->allowedPointIds($data['points']));
            }

            $this->audit->record($actor, 'Изменены данные сотрудника', $user->login.' · '.$user->name, $before, $user->name.' · '.$user->login.' · '.$user->role->value, 'user');

            return $user;
        });
    }

    /**
     * Blocking revokes the token immediately — the device drops to the login screen
     * even with the app open.
     */
    public function toggleAccess(User $user, string $actor): User
    {
        return DB::transaction(function () use ($user, $actor): User {
            $user->update(['is_active' => ! $user->is_active]);

            if (! $user->is_active) {
                // Токен отзывается здесь и сейчас: устройство выкидывает на экран
                // входа, даже если приложение открыто прямо в эту минуту.
                $user->tokens()->delete();
                $this->endWebSessions($user);
                $user->deviceRecord?->update(['is_blocked' => true]);
                $this->audit->record($actor, 'Сотрудник заблокирован', $user->login.' · '.$user->name, 'активен', 'заблокирован', 'user');
                $this->audit->record('Система', 'Сессия завершена', ($user->deviceRecord?->model ?? $user->device).' · токен отозван', null, null, 'auth');
            } else {
                $user->deviceRecord?->update(['is_blocked' => false]);
                $this->audit->record($actor, 'Доступ возвращён', $user->login.' · '.$user->name, 'заблокирован', 'активен', 'user');
            }

            return $user;
        });
    }

    /**
     * @return array{password: string}
     */
    public function changePassword(User $user, string $password, bool $endSessions, string $actor): array
    {
        return DB::transaction(function () use ($user, $password, $endSessions, $actor): array {
            $user->update(['password' => Hash::make($password)]);

            if ($endSessions) {
                // «Завершить активные сессии»: старый токен перестаёт работать сразу,
                // каталог скачается заново при первом входе.
                $user->tokens()->delete();
                $this->endWebSessions($user);
                $user->deviceRecord?->update(['data_version' => 0, 'lag' => 0]);
            }

            $this->audit->record($actor, 'Смена пароля', $user->login.' · '.$user->name, null, null, 'user');

            return ['password' => $password];
        });
    }

    public function suggestPassword(): string
    {
        return $this->passwords->generate();
    }

    public function loginExists(string $login): bool
    {
        return $this->users->loginExists($login);
    }

    /**
     * Сессии панели живут в базе, поэтому их строки удаляются вместе с токенами —
     * открытая вкладка теряет доступ, не дожидаясь истечения куки.
     */
    private function endWebSessions(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->delete();
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function allowedPointIds(array $ids): array
    {
        return Point::whereIn('id', $ids)->pluck('id')->all();
    }
}
