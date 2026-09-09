<?php

namespace App\Http\Resources\V2;

use App\Http\Resources\V2\Concerns\HasServerMeta;
use App\Services\V2\Data\SyncResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Итог синхронизации. `up_to_date` приходит готовым флагом, а не выводится телефоном
 * из сравнения чисел: решение «тянуть каталог заново» принимает сервер.
 */
class SyncResource extends JsonResource
{
    use HasServerMeta;

    public function __construct(SyncResult $result)
    {
        parent::__construct($result);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'catalog_version' => $this->resource->catalogVersion,
            'previous_version' => $this->resource->previousVersion,
            'lag' => $this->resource->lag,
            'up_to_date' => $this->resource->upToDate(),
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
