<?php

namespace App\Http\Resources\V2;

use App\Http\Resources\V2\Concerns\HasServerMeta;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Подтверждение выполненного действия — «историю очистили», «токен отозвали».
 * Ответ пустым телом не отдаётся: приложению нужен тот же конверт `data`/`meta`,
 * что и у остальных вызовов, иначе на каждый вызов заводится своя ветка разбора.
 */
class AcknowledgementResource extends JsonResource
{
    use HasServerMeta;

    /**
     * @param  array<string, bool>  $payload
     */
    public function __construct(array $payload)
    {
        parent::__construct($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return ['meta' => $this->serverMeta()];
    }
}
