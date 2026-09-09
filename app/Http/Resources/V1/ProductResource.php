<?php

namespace App\Http\Resources\V1;

use App\Models\Product;
use App\Models\ProductStock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Catalogue row as the mobile application receives it.
 *
 * A field hidden for the role is not blanked out — the key is absent from the payload
 * entirely, so it cannot be recovered by intercepting the traffic.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @param  list<string>  $visibleFields
     */
    public function __construct($resource, private readonly array $visibleFields = [])
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = ['id' => (string) $this->id];

        if ($this->sees('mainCode')) {
            $payload['main_code'] = $this->main_code;
        }

        if ($this->sees('name')) {
            $payload['name'] = $this->name;
        }

        if ($this->sees('sku')) {
            $payload['sku'] = $this->sku;
        }

        if ($this->sees('barcode')) {
            $payload['barcode'] = $this->barcode;
        }

        if ($this->sees('retail')) {
            $payload['retail'] = ['amount' => (int) round((float) $this->price * 100), 'currency' => 'TMT'];
        }

        /* Оптовую цену видит менеджер. У товара её может не быть — тогда ключ не приходит. */
        if ($this->sees('wholesale') && $this->wholesale_price !== null) {
            $payload['wholesale'] = ['amount' => (int) round((float) $this->wholesale_price * 100), 'currency' => 'TMT'];
        }

        if ($this->sees('discount')) {
            $payload['discount'] = (float) $this->discount;
            $payload['final'] = [
                'amount' => (int) round($this->finalPrice() * 100),
                'currency' => 'TMT',
            ];
        }

        if ($this->sees('stock')) {
            $payload['stock'] = $this->whenLoaded('stocks', fn (): array => $this->stocks
                ->map(fn (ProductStock $stock): array => [
                    'point_id' => (string) $stock->point_id,
                    'qty' => $stock->qty,
                ])->values()->all(), []);
        }

        $payload['status'] = $this->status;

        return $payload;
    }

    private function sees(string $field): bool
    {
        return in_array($field, $this->visibleFields, true);
    }
}
