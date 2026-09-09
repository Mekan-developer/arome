<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\IssueTokenRequest;
use App\Http\Resources\V2\AcknowledgementResource;
use App\Http\Resources\V2\TokenResource;
use App\Services\V2\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Выдача и отзыв токена устройства. Проверка пароля, блокировки и запись в журнал —
 * в {@see TokenService}.
 */
class TokenApiController extends Controller
{
    public function __construct(
        private readonly TokenService $tokens,
    ) {}

    public function store(IssueTokenRequest $request): JsonResponse
    {
        return (new TokenResource($this->tokens->issue($request->credentials())))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroy(Request $request): AcknowledgementResource
    {
        $this->tokens->revoke($request->user());

        return new AcknowledgementResource(['revoked' => true]);
    }
}
