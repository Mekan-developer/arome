<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SyncRequest;
use App\Http\Resources\V2\SyncResource;
use App\Services\V2\SyncService;

/**
 * Устройство сообщает применённую ревизию каталога и получает своё отставание.
 */
class SyncApiController extends Controller
{
    public function __construct(
        private readonly SyncService $sync,
    ) {}

    public function store(SyncRequest $request): SyncResource
    {
        return new SyncResource(
            $this->sync->apply($request->user(), $request->heartbeat()),
        );
    }
}
