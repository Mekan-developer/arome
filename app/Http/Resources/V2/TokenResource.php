<?php

namespace App\Http\Resources\V2;

use App\Http\Resources\V2\Concerns\HasServerMeta;
use App\Services\V2\Data\IssuedToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ответ на вход: сам токен и минимум о сотруднике — имя для шапки и роль, по которой
 * приложение решает, какие экраны показывать.
 */
class TokenResource extends JsonResource
{
    use HasServerMeta;

    public function __construct(IssuedToken $token)
    {
        parent::__construct($token);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->resource->plainTextToken,
            'user' => [
                'id' => (string) $this->resource->user->id,
                'name' => $this->resource->user->name,
                'role' => $this->resource->user->role->value,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return ['meta' => $this->serverMeta()];
    }
}
