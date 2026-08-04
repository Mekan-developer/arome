<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IssueTokenRequest;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

/**
 * Выдача токена на устройство. Токен именной — «выйти на потерянном телефоне»
 * работает как отзыв конкретного устройства, а не всех сессий сотрудника.
 */
class TokenApiController extends Controller
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuditService $audit,
    ) {}

    public function store(IssueTokenRequest $request): JsonResponse
    {
        $credentials = $request->credentials();
        $user = $this->users->findByLogin($credentials['login']);

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_credentials',
                    'message' => 'Неверный логин или пароль',
                ],
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'error' => [
                    'code' => 'account_blocked',
                    'message' => 'Учётная запись заблокирована',
                ],
            ], 403);
        }

        // Один токен на устройство: повторный вход с того же телефона заменяет старый.
        $user->tokens()->where('name', $credentials['device'])->delete();
        $token = $user->createToken($credentials['device']);

        $user->forceFill(['last_login_at' => now(), 'device' => $credentials['device']])->save();

        $this->audit->record($user->name, 'Вход с устройства', $credentials['device'], null, null, 'auth');

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'user' => [
                    'id' => (string) $user->id,
                    'name' => $user->name,
                    'role' => $user->role->value,
                ],
            ],
            'meta' => ['server_time' => now()->toIso8601ZuluString()],
        ], 201);
    }

    /**
     * Выход: отзывается только токен этого устройства.
     */
    public function destroy(): JsonResponse
    {
        request()->user()->currentAccessToken()?->delete();

        return response()->json(['data' => ['revoked' => true]]);
    }
}
