<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SyncRequest;
use App\Repositories\DeviceRepository;
use App\Services\SyncService;
use Illuminate\Http\JsonResponse;

/**
 * Устройство сообщает, какую ревизию каталога оно применило, и получает дельту.
 */
class SyncApiController extends Controller
{
    public function __construct(
        private readonly SyncService $sync,
        private readonly DeviceRepository $devices,
    ) {}

    public function store(SyncRequest $request): JsonResponse
    {
        $user = $request->user();
        $device = $this->devices->forUser($user->id);

        if ($device?->is_blocked) {
            return response()->json([
                'error' => [
                    'code' => 'device_blocked',
                    'message' => 'Доступ заблокирован, сессия завершена',
                ],
            ], 403);
        }

        $result = $this->sync->apply($user, $device, $request->sinceVersion());

        return response()->json([
            'data' => $result,
            'meta' => ['server_time' => now()->toIso8601ZuluString()],
        ]);
    }
}
