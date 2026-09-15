<?php

namespace App\Http\Resources\V2;

use App\Http\Resources\V2\Concerns\HasServerMeta;
use App\Models\Product;
use App\Models\ProductStock;
use App\Services\V2\Data\CatalogCard;
use App\Services\V2\Data\FieldAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Строка каталога, какой её получает приложение.
 *
 * Закрытое роли поле не обнуляется — ключа в ответе нет вовсе, поэтому его нельзя
 * достать, перехватив трафик. Цены — целые манаты (как в прайсе и панели), валюта
 * всегда TMT и в каждую строку не дублируется. Статус не отдаётся: в каталог API
 * попадают только активные товары.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    use HasServerMeta;

    public function __construct(Product $product, private readonly FieldAccess $access)
    {
        parent::__construct($product);
    }

    /**
     * Ответ на поднесённый штрихкод: карточка приезжает из сервиса вместе с правами.
     */
    public static function forCard(CatalogCard $card): self
    {
        return new self($card->product, $card->access);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = ['id' => (string) $this->id];

        if ($this->access->sees('mainCode')) {
            $payload['main_code'] = $this->main_code;
        }

        if ($this->access->sees('name')) {
            $payload['name'] = $this->name;
        }

        if ($this->access->sees('sku')) {
            $payload['sku'] = $this->sku;
        }

        if ($this->access->sees('barcode')) {
            $payload['barcode'] = $this->barcode;
        }

        if ($this->access->sees('retail')) {
            $payload['retail'] = $this->money((float) $this->price);
        }

        if ($this->access->sees('wholesale') && $this->wholesale_price !== null) {
            $payload['wholesale'] = $this->money((float) $this->wholesale_price);
        }

        if ($this->access->sees('discount')) {
            $payload['discount'] = (float) $this->discount;
            $payload['final'] = $this->money($this->finalPrice());
        }

        if ($this->access->withStock) {
            $payload['stock'] = $this->whenLoaded('stocks', fn (): array => $this->stocks
                ->map(fn (ProductStock $stock): array => [
                    'point_id' => (string) $stock->point_id,
                    'qty' => $stock->qty,
                ])->values()->all(), []);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return ['meta' => $this->serverMeta()];
    }

    /**
     * Целое число манат — как после импорта прайса. Копейки и код валюты в v2 не едут.
     */
    private function money(float $amount): int
    {
        return (int) round($amount);
    }
}
